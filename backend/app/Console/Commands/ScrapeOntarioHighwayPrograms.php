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
    private const ARCGIS_ITEM_URL = 'https://www.arcgis.com/sharing/rest/content/items/';
    private const MAP_VIEWER_URL = 'https://www.arcgis.com/apps/mapviewer/index.html';

    // Fallbacks (August 2026 refresh), used only when live discovery from the
    // ontario.ca page fails. MTO republishes the dashboard with new layers each
    // program year, so discovery is the primary path.
    private const FALLBACK_WEBMAP_ID = '5c6a789b9c804864966e6053273bfe62';
    private const FALLBACK_FEATURE_LAYER_URL = 'https://services.arcgis.com/6iGx1Dq91oKtcE7x/arcgis/rest/services/OHP_FilterHelper_V3/FeatureServer/0';

    private string $featureLayerUrl = self::FALLBACK_FEATURE_LAYER_URL;
    private ?string $webMapId = self::FALLBACK_WEBMAP_ID;

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
            $this->discoverDataSource();
            $this->line("Using layer {$this->featureLayerUrl} (web map {$this->webMapId})");

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
                        $itemsUpserted += $this->upsertProject($attributes, $feature['centroid'] ?? null, $lastUpdated);
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

    /**
     * Resolve the current feature layer and web map from the live ontario.ca page:
     * page -> embedded ArcGIS dashboard -> its web map -> the "FilterHelper" project layer.
     */
    private function discoverDataSource(): void
    {
        try {
            $html = $this->http()->get(self::SOURCE_URL)->throw()->body();
            if (!preg_match('~arcgis\.com/apps/dashboards/([0-9a-f]{32})~i', $html, $match)) {
                throw new \RuntimeException('dashboard link not found on page');
            }

            $dashboard = $this->http()->get(self::ARCGIS_ITEM_URL.$match[1].'/data', ['f' => 'json'])->throw()->json() ?? [];
            $webMapId = null;
            foreach ($dashboard['desktopView']['widgets'] ?? $dashboard['widgets'] ?? [] as $widget) {
                if (($widget['type'] ?? null) === 'mapWidget' && !empty($widget['itemId'])) {
                    $webMapId = $widget['itemId'];
                    break;
                }
            }
            if (!$webMapId) {
                throw new \RuntimeException('map widget not found in dashboard '.$match[1]);
            }

            $webMap = $this->http()->get(self::ARCGIS_ITEM_URL.$webMapId.'/data', ['f' => 'json'])->throw()->json() ?? [];
            $layerUrl = $this->findProjectLayerUrl($webMap['operationalLayers'] ?? []);
            if (!$layerUrl) {
                throw new \RuntimeException('FilterHelper layer not found in web map '.$webMapId);
            }

            $this->webMapId = $webMapId;
            $this->featureLayerUrl = $layerUrl;
        } catch (Throwable $exception) {
            $this->warn('Ontario highway source discovery failed, using fallback layer: '.$exception->getMessage());
        }
    }

    private function findProjectLayerUrl(array $layers): ?string
    {
        foreach ($layers as $layer) {
            $url = $layer['url'] ?? '';
            if ($url && preg_match('~FilterHelper~i', ($layer['title'] ?? '').' '.$url)) {
                return $url;
            }
            if ($found = $this->findProjectLayerUrl($layer['layers'] ?? [])) {
                return $found;
            }
        }

        return null;
    }

    private function http()
    {
        return Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders([
                'Accept' => 'application/json, text/html, */*',
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ]);
    }

    /**
     * Ontario has no per-project pages; the closest deep link is the program's
     * ArcGIS web map, centred and pinned on the project.
     */
    private function projectMapUrl(?array $centroid, $projectLengthKm): string
    {
        $lon = $centroid['x'] ?? null;
        $lat = $centroid['y'] ?? null;

        if (!$this->webMapId || !is_numeric($lon) || !is_numeric($lat)) {
            return self::SOURCE_URL;
        }

        $length = (float) $projectLengthKm;
        $level = match (true) {
            $length > 20 => 11,
            $length > 5 => 13,
            $length > 0 => 14,
            default => 16,
        };

        $point = round((float) $lon, 6).','.round((float) $lat, 6);

        return self::MAP_VIEWER_URL.'?webmap='.rawurlencode($this->webMapId)
            ."&center={$point}&level={$level}&marker={$point}";
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
            ->get($this->featureLayerUrl, [
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
            ->get($this->featureLayerUrl.'/query', [
                'f' => 'json',
                'where' => '1=1',
                'outFields' => '*',
                'orderByFields' => $objectIdField.' ASC',
                'resultOffset' => $offset,
                'resultRecordCount' => $limit,
                'returnGeometry' => 'false',
                'returnCentroid' => 'true',
                'outSR' => '4326',
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

    private function upsertProject(array $row, ?array $centroid, ?Carbon $lastUpdated): int
    {
        // The 2026 layer changed field-name casing (e.g. ESTIMATED_COST_RANGE -> Estimated_Cost_Range).
        $row = array_change_key_case($row, CASE_UPPER);

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
            'source_url' => $this->projectMapUrl($centroid, $projectLength),
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
