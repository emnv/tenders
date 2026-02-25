<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeKenoraTenders extends Command
{
    protected $signature = 'scrape:kenora-tenders';

    protected $description = 'Ingest tender documents from City of Kenora list view.';

    private const SOURCE_SITE_KEY = 'kenora-tenders';
    private const SOURCE_SITE_NAME = 'City of Kenora Tenders';
    private const BASE_URL = 'https://listview.kenora.ca/Listview.aspx?root=Tenders&wmode=transparent';
    private const BASE_HOST = 'https://listview.kenora.ca';
    private const LOCATION = 'Kenora, ON';

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
            $response = Http::timeout(30)
                ->retry(3, 500)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer' => 'https://listview.kenora.ca/',
                    'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
                ])
                ->withOptions([
                    'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
                ])
                ->get(self::BASE_URL);

            if (!$response->successful()) {
                throw new \RuntimeException('Kenora list view request failed with status '.$response->status());
            }

            $html = $response->body();
            $rows = $this->parseRows($html);
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Kenora tender documents.");

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseRows(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $links = $xpath->query("//table[@id='dgFileList']//a[@href]");
        $rows = [];

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $title = trim(html_entity_decode($link->textContent ?? '', ENT_QUOTES | ENT_HTML5));

            if ($href === '' || $title === '') {
                continue;
            }

            $rows[] = [
                'title' => $title,
                'href' => $href,
            ];
        }

        return $rows;
    }

    private function upsertProject(array $row): int
    {
        $href = $row['href'] ?? null;
        $title = $row['title'] ?? null;

        if (!$href || !$title) {
            return 0;
        }

        $sourceUrl = $this->absoluteUrl($href);
        $externalId = $this->externalIdFromHref($href);

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => $externalId,
        ];

        $values = [
            'title' => $title,
            'description' => null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $sourceUrl,
            'location' => self::LOCATION,
            'source_status' => 'Open',
            'source_timezone' => 'America/Winnipeg',
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => [
                'href' => $href,
                'title' => $title,
            ],
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    private function absoluteUrl(string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return rtrim(self::BASE_HOST, '/') . '/' . ltrim($href, '/');
    }

    private function externalIdFromHref(string $href): string
    {
        $clean = preg_replace('/[#?].*$/', '', $href);
        $clean = trim($clean, '/');

        return $clean !== '' ? $clean : md5($href);
    }
}
