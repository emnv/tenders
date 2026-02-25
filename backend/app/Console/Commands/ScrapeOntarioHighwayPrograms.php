<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeOntarioHighwayPrograms extends Command
{
    protected $signature = 'scrape:ontario-highway-programs';

    protected $description = 'Ingest Ontario highway program projects (HTML table).';

    private const SOURCE_SITE_KEY = 'ontario-highway-programs';
    private const SOURCE_SITE_NAME = 'Ontario Highway Programs';
    private const SOURCE_URL = 'https://www.ontario.ca/page/ontarios-highway-programs';
    private const FEATURE_LAYER_URL = 'https://services.arcgis.com/6iGx1Dq91oKtcE7x/arcgis/rest/services/OHP_Buff_FilterHelper_June2025/FeatureServer/40';

    public function handle(): int
    {
        $itemsFound = 0;
        $itemsUpserted = 0;

        $runId = DB::table('scrape_runs')->insertGetId([
            'source_site_key' => self::SOURCE_SITE_KEY,
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $layerInfo = $this->fetchLayerInfo();
            $lastUpdated = $this->lastUpdatedFromLayer($layerInfo);

            $objectIdField = $layerInfo['objectIdField'] ?? $layerInfo['objectIdFieldName'] ?? 'OBJECTID';
            $maxRecordCount = (int) ($layerInfo['maxRecordCount'] ?? 1000);
            $pageSize = max(1, min($maxRecordCount, 2000));

            $offset = 0;
            $hasMore = true;

            while ($hasMore) {
                $payload = $this->fetchFeaturePage($offset, $pageSize, $objectIdField);
                $features = $payload['features'] ?? [];

                if (empty($features)) {
                    break;
                }

                foreach ($features as $feature) {
                    $attributes = $feature['attributes'] ?? [];
                    if (!empty($attributes)) {
                        $itemsFound++;
                        $itemsUpserted += $this->upsertProject($attributes, $lastUpdated);
                    }
                }

                $offset += count($features);
                $hasMore = (bool) ($payload['exceededTransferLimit'] ?? false);
            }

            DB::table('scrape_runs')->where('id', $runId)->update([
                'status' => 'success',
                'finished_at' => now(),
                'items_found' => $itemsFound,
                'items_upserted' => $itemsUpserted,
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            DB::table('scrape_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'finished_at' => now(),
                'items_found' => $itemsFound,
                'items_upserted' => $itemsUpserted,
                'message' => $exception->getMessage(),
                'updated_at' => now(),
            ]);

            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Ontario highway program projects.");

        return Command::SUCCESS;
    }

    private function fetchLayerInfo(): array
    {
        $response = Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders([
                'Accept' => 'application/json, text/plain, */*',
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ])
            ->get(self::FEATURE_LAYER_URL, [
                'f' => 'json',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Ontario highway programs layer info request failed with status '.$response->status());
        }

        return $response->json() ?? [];
    }

    private function fetchFeaturePage(int $offset, int $limit, string $objectIdField): array
    {
        $response = Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders([
                'Accept' => 'application/json, text/plain, */*',
                'Referer' => self::SOURCE_URL,
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ])
            ->get(self::FEATURE_LAYER_URL.'/query', [
                'f' => 'json',
                'where' => '1=1',
                'outFields' => '*',
                'orderByFields' => $objectIdField.' ASC',
                'resultOffset' => $offset,
                'resultRecordCount' => $limit,
                'returnGeometry' => 'false',
                'resultType' => 'standard',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Ontario highway programs query failed with status '.$response->status());
        }

        return $response->json() ?? [];
    }

    private function lastUpdatedFromLayer(array $layerInfo): ?Carbon
    {
        $timestamp = $layerInfo['editingInfo']['dataLastEditDate'] ?? null;
        if (!$timestamp) {
            return null;
        }

        try {
            return Carbon::createFromTimestampMs((int) $timestamp, 'America/Toronto');
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function upsertProject(array $row, ?Carbon $lastUpdated): int
    {
        $programType = $row['PROGRAM_TYPE'] ?? null;
        $startYear = $row['PROJECT_START_YEAR'] ?? null;
        $region = $row['REGION_NAME'] ?? null;
        $highway = $row['WP_HIGHWAYS'] ?? null;
        $location = $row['GWP_SHORT_DESCRIPTION'] ?? null;
        $typeOfWork = $row['HP_TYPE_OF_WORK'] ?? null;
        $status = $row['HIGHWAY_PROGRAM_STATUS'] ?? null;
        $targetCompletion = $row['PROJECT_COMPLETION_YEAR'] ?? null;
        $contract = $row['CONTRACT_NUMBER'] ?? null;
        $projectLength = $row['PROJECT_LENGTH'] ?? null;
        $costRange = $row['ESTIMATED_COST_RANGE'] ?? null;
        $engineeringStatus = $row['ENGINEERING_STATUS'] ?? null;
        $deliveryMethod = $row['DELIVERY_METHOD'] ?? null;

        if (!$location && !$highway && !$contract) {
            return 0;
        }

        $titleParts = [];
        if ($highway) {
            $titleParts[] = 'Highway '.$highway;
        }
        if ($location) {
            $titleParts[] = $location;
        }
        if (!$titleParts && $programType) {
            $titleParts[] = $programType;
        }

        $title = implode(' - ', $titleParts);

        $externalIdSeed = implode('|', [
            $programType,
            $startYear,
            $region,
            $highway,
            $location,
            $typeOfWork,
            $status,
            $targetCompletion,
            $contract,
        ]);

        $externalId = $contract ?: md5($externalIdSeed);

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => (string) $externalId,
        ];

        // Only create new projects if they have an active status.
        // Always update existing projects so status changes are captured.
        $exists = Project::where($attributes)->exists();

        if (!$exists && !$this->isActiveStatus($status)) {
            return 0;
        }

        $descriptionParts = array_filter([
            $typeOfWork ? "Type of work: {$typeOfWork}" : null,
            $status ? "Status: {$status}" : null,
            $engineeringStatus ? "Engineering: {$engineeringStatus}" : null,
            $deliveryMethod ? "Delivery: {$deliveryMethod}" : null,
            $costRange ? "Estimated cost: {$costRange}" : null,
        ]);

        $values = [
            'title' => $title ?: 'Ontario Highway Program Project',
            'description' => !empty($descriptionParts) ? implode(' | ', $descriptionParts) : null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => self::SOURCE_URL,
            'location' => $location ?: ($region ?: 'Ontario'),
            'published_at' => $lastUpdated,
            'date_publish_at' => $lastUpdated,
            'source_status' => $status,
            'source_scope' => $programType,
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => [
                'program_type' => $programType,
                'start_year' => $startYear,
                'region' => $region,
                'highway' => $highway,
                'location' => $location,
                'type_of_work' => $typeOfWork,
                'highway_program_status' => $status,
                'target_completion' => $targetCompletion,
                'contract_number' => $contract,
                'project_length_km' => $projectLength,
                'estimated_cost_range' => $costRange,
                'engineering_status' => $engineeringStatus,
                'delivery_method' => $deliveryMethod,
                'data_last_updated' => $lastUpdated ? $lastUpdated->toDateString() : null,
            ],
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    private function cleanText(string $value): string
    {
        $clean = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        $clean = preg_replace('/\s+/', ' ', $clean);

        return trim($clean);
    }

    /**
     * Determine whether a highway program status is active (not completed).
     */
    private function isActiveStatus(?string $status): bool
    {
        if ($status === null || trim($status) === '') {
            return true;
        }

        $closedStatuses = ['completed', 'cancelled', 'closed'];

        return !in_array(strtolower(trim($status)), $closedStatuses, true);
    }
}
