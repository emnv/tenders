<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeInfrastructureOntario extends Command
{
    protected $signature = 'scrape:infrastructure-ontario {--pages=10 : Maximum pages to fetch}';

    protected $description = 'Ingest Infrastructure Ontario projects in procurement stage.';

    private const SOURCE_SITE_KEY = 'infrastructure-ontario-projects';
    private const SOURCE_SITE_NAME = 'Infrastructure Ontario Projects';
    private const BASE_URL = 'https://www.infrastructureontario.ca/en/what-we-do/projectssearch/GetSearchResults';
    private const FRONTEND_BASE_URL = 'https://www.infrastructureontario.ca';
    private const FACETS = 'projectstage:inprocurement';

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
            $maxPages = max(1, (int) $this->option('pages'));
            $page = 1;
            $totalPages = 1;

            while ($page <= $maxPages && $page <= $totalPages) {
                $payload = $this->fetchPage($page);

                $totalCount = (int) ($payload['totalCount'] ?? 0);
                $pageSize = (int) ($payload['paginationViewModel']['pageSize'] ?? 6);
                $totalPages = (int) ($payload['paginationViewModel']['totalNumPage'] ?? ($pageSize > 0 ? ceil($totalCount / $pageSize) : 1));

                $rows = $payload['searchResults']['rowViewModels'] ?? [];
                $itemsFound += count($rows);

                foreach ($rows as $row) {
                    $itemsUpserted += $this->upsertProject($row);
                }

                $page++;
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Infrastructure Ontario projects.");

        return Command::SUCCESS;
    }

    private function fetchPage(int $page): array
    {
        $response = Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders([
                'Accept' => 'application/json, text/plain, */*',
                'Referer' => 'https://www.infrastructureontario.ca/en/what-we-do/projectssearch/?cpage=1&facets=projectstage%3Ainprocurement',
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ])
            ->get(self::BASE_URL, [
                'facets' => self::FACETS,
                'cpage' => $page,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Infrastructure Ontario request failed with status '.$response->status());
        }

        return $response->json() ?? [];
    }

    private function upsertProject(array $row): int
    {
        $title = $row['tileTitle'] ?? null;
        $tileUrl = $row['tileUrl'] ?? null;

        if (!$title || !$tileUrl) {
            return 0;
        }

        $externalId = trim($tileUrl, '/');
        $sourceUrl = self::FRONTEND_BASE_URL . $tileUrl;

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => $externalId,
        ];

        $values = [
            'title' => $title,
            'description' => $row['tileShortDesc'] ?? null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $sourceUrl,
            'location' => 'Ontario',
            'source_scope' => 'Project Stage: In Procurement',
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $row,
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }
}
