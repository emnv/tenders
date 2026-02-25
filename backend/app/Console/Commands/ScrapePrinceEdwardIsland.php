<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapePrinceEdwardIsland extends Command
{
    protected $signature = 'scrape:pei-tenders {--years=5}';

    protected $description = 'Ingest open tenders from Prince Edward Island (workflow JSON endpoint).';

    private const SOURCE_SITE_KEY = 'pei-tenders';
    private const SOURCE_SITE_NAME = 'Prince Edward Island Tenders';
    private const LOCATION_DEFAULT = 'Prince Edward Island';
    private const BASE_URL = 'https://wdf.princeedwardisland.ca/api/workflow';
    private const FRONTEND_BASE_URL = 'https://www.princeedwardisland.ca/en/feature/search-for-tenders-and-procurement-opportunities/#/service/Tenders/TenderView';

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
            $yearsToPull = max(1, (int) $this->option('years'));
            $currentYear = (int) now()->year;
            $rows = [];

            for ($offset = 0; $offset < $yearsToPull; $offset++) {
                $year = $currentYear - $offset;
                $payload = $this->fetchPayload($year);
                $rows = array_merge($rows, $this->parseRows($payload));
            }

            $itemsFound = count($rows);

            foreach ($rows as $row) {
                $itemsUpserted += $this->upsertProject($row);
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} PEI tenders.");

        return Command::SUCCESS;
    }

    private function fetchPayload(int $year): array
    {
        $response = Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Origin' => 'https://www.princeedwardisland.ca',
                'Referer' => 'https://www.princeedwardisland.ca/en/feature/search-for-tenders-and-procurement-opportunities/',
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ])
            ->post(self::BASE_URL, [
                'appName' => 'Tenders',
                'featureName' => 'Tenders',
                'metaVars' => [
                    'service_id' => null,
                    'save_location' => null,
                ],
                'queryVars' => [
                    'keyword' => null,
                    'category' => null,
                    'status' => 'Open',
                    'organization' => null,
                    'publication_year' => (string) $year,
                    'wdf_url_query' => 'true',
                    'service' => 'Tenders',
                    'activity' => 'TenderSearch',
                ],
                'queryName' => 'TenderSearch',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('PEI workflow request failed with status '.$response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseRows(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $table = $this->findNodeByType($data, 'TableV2');

        if (!$table || empty($table['children'])) {
            return [];
        }

        $rows = array_filter($table['children'], fn ($child) => ($child['type'] ?? '') === 'TableV2Row');
        $results = [];

        foreach ($rows as $row) {
            $cells = $row['children'] ?? [];
            if (count($cells) < 5) {
                continue;
            }

            $firstCell = $cells[0] ?? [];
            $link = $this->findNodeByType($firstCell['children'] ?? [], 'LinkV2');
            $solicitationNumber = $link['data']['text'] ?? $this->cellText($firstCell);
            $tenderId = $link['data']['queryParams']['tender_id'] ?? null;

            $title = $this->cellText($cells[1] ?? []);
            $organization = $this->cellText($cells[2] ?? []);
            $publishedRaw = $this->cellText($cells[3] ?? []);
            $closingRaw = $this->cellText($cells[4] ?? []);

            if (!$solicitationNumber || !$title) {
                continue;
            }

            $results[] = [
                'tender_id' => $tenderId,
                'solicitation_number' => $solicitationNumber,
                'title' => $title,
                'organization' => $organization,
                'published_raw' => $publishedRaw,
                'closing_raw' => $closingRaw,
            ];
        }

        return $results;
    }

    private function upsertProject(array $row): int
    {
        $externalId = $row['tender_id'] ?? $row['solicitation_number'] ?? null;

        if (!$externalId) {
            return 0;
        }

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => (string) $externalId,
        ];

        $publishedAt = $this->parseDate($row['published_raw'] ?? null);
        $closingAt = $this->parseDate($row['closing_raw'] ?? null);

        $values = [
            'title' => $row['title'],
            'description' => null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $this->buildTenderUrl($row['tender_id'] ?? null),
            'location' => self::LOCATION_DEFAULT,
            'published_at' => $publishedAt,
            'date_publish_at' => $publishedAt,
            'date_closing_at' => $closingAt,
            'solicitation_number' => $row['solicitation_number'] ?? null,
            'source_status' => 'Open',
            'source_timezone' => 'America/Halifax',
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $row,
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value, 'America/Halifax');
    }

    private function buildTenderUrl(?string $tenderId): ?string
    {
        if (!$tenderId) {
            return null;
        }

        return self::FRONTEND_BASE_URL.'?tender_id='.$tenderId;
    }

    private function cellText(array $cell): ?string
    {
        $text = $cell['data']['text'] ?? null;

        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        if (!empty($cell['children'])) {
            foreach ($cell['children'] as $child) {
                $childText = $child['data']['text'] ?? null;
                if (is_string($childText) && trim($childText) !== '') {
                    return trim($childText);
                }
            }
        }

        return null;
    }

    private function findNodeByType(array $nodes, string $type): ?array
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === $type) {
                return $node;
            }

            if (!empty($node['children'])) {
                $found = $this->findNodeByType($node['children'], $type);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

}
