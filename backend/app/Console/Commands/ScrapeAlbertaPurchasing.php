<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeAlbertaPurchasing extends Command
{
    protected $signature = 'scrape:alberta-purchasing {--limit=50 : Page size} {--pages=10 : Maximum pages to fetch}';

    protected $description = 'Ingest opportunities from Alberta Purchasing portal (JSON API).';

    private const SOURCE_SITE_KEY = 'alberta-purchasing';
    private const SOURCE_SITE_NAME = 'Alberta Purchasing';
    private const BASE_URL = 'https://purchasing.alberta.ca/api/opportunity/search';
    private const FRONTEND_BASE_URL = 'https://purchasing.alberta.ca';

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
            $limit = max(1, (int) $this->option('limit'));
            $maxPages = max(1, (int) $this->option('pages'));

            $page = 1;
            $totalPages = 1;
            $offset = 1;

            while ($page <= $maxPages && $page <= $totalPages) {
                $payload = $this->fetchPage($limit, $offset);

                $totalCount = (int) ($payload['totalCount'] ?? 0);
                $totalPages = (int) ceil($totalCount / $limit);

                $rows = $payload['values'] ?? [];
                $itemsFound += count($rows);

                foreach ($rows as $row) {
                    $itemsUpserted += $this->upsertProject($row);
                }

                $page++;
                $offset += $limit;
                usleep(250000);
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Alberta Purchasing opportunities.");

        return Command::SUCCESS;
    }

    private function fetchPage(int $limit, int $offset): array
    {
        $response = Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders([
                'Accept' => 'application/json, text/plain, */*',
                'Content-Type' => 'application/json',
                'Origin' => 'https://purchasing.alberta.ca',
                'Referer' => 'https://purchasing.alberta.ca/search',
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ])
            ->post(self::BASE_URL, [
                'query' => '',
                'queryMode' => 'standard',
                'includeEnhancedMatchIds' => false,
                'filter' => [
                    'solicitationNumber' => '',
                    'categories' => [],
                    'statuses' => [],
                    'agreementTypes' => [],
                    'solicitationTypes' => [],
                    'opportunityTypes' => [],
                    'deliveryRegions' => [],
                    'deliveryRegion' => '',
                    'organizations' => [],
                    'unspsc' => [],
                    'postDateRange' => '$$custom',
                    'closeDateRange' => '$$custom',
                    'onlyBookmarked' => false,
                    'onlyInterestExpressed' => false,
                ],
                'limit' => $limit,
                'offset' => $offset,
                'sortOptions' => [
                    [
                        'field' => 'PostDateTime',
                        'direction' => 'desc',
                    ],
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Alberta Purchasing request failed with status '.$response->status());
        }

        return $response->json() ?? [];
    }

    private function upsertProject(array $row): int
    {
        $externalId = $row['id'] ?? null;
        $title = $row['title'] ?? $row['shortTitle'] ?? null;

        if (!$externalId || !$title) {
            return 0;
        }

        $status = $row['statusCode'] ?? null;

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => (string) $externalId,
        ];

        // Only create new projects if they have an open status.
        // Always update existing projects so status changes are captured.
        $exists = Project::where($attributes)->exists();

        if (!$exists && !$this->isOpenStatus($status)) {
            return 0;
        }

        $postDate = $this->parseDate($row['postDateTime'] ?? null);
        $closeDate = $this->parseDate($row['closeDateTime'] ?? null);

        $sourceUrl = $row['externalOriginLink'] ?? (self::FRONTEND_BASE_URL . '/opportunity/' . $externalId);

        $regions = $row['regionOfDelivery'] ?? [];
        $location = !empty($regions) ? implode(', ', $regions) : 'Alberta';

        $values = [
            'title' => $title,
            'description' => $row['projectDescription'] ?? null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $sourceUrl,
            'location' => $location,
            'published_at' => $postDate,
            'date_publish_at' => $postDate,
            'date_closing_at' => $closeDate,
            'solicitation_number' => $row['solicitationNumber'] ?? null,
            'solicitation_type' => $row['solicitationTypeCode'] ?? null,
            'purchasing_group' => $row['contractingOrganization'] ?? null,
            'buyer_name' => $row['contractingOrganization'] ?? null,
            'source_status' => $status,
            'source_scope' => $row['opportunityTypeCode'] ?? null,
            'source_timezone' => 'America/Edmonton',
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $row,
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    /**
     * Determine whether a status string represents an open/active opportunity.
     */
    private function isOpenStatus(?string $status): bool
    {
        if ($status === null || trim($status) === '') {
            return true;
        }

        $openStatuses = ['open', 'active', 'published'];

        return in_array(strtolower(trim($status)), $openStatuses, true);
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value, 'America/Edmonton');
        } catch (Throwable $exception) {
            return null;
        }
    }
}
