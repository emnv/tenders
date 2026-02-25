<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeWindsorBids extends Command
{
    protected $signature = 'scrape:windsor-bids';

    protected $description = 'Ingest open bids from City of Windsor (HTML table parsing).';

    private const SOURCE_SITE_KEY = 'windsor-bids-tenders';
    private const SOURCE_SITE_NAME = 'Windsor Bids & Tenders';
    private const LOCATION_DEFAULT = 'Windsor, ON';
    private const BASE_URL = 'https://opendata.citywindsor.ca/Tools/BidsAndTenders';

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
            $html = $this->fetchPage();
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Windsor bids.");

        return Command::SUCCESS;
    }

    private function fetchPage(): string
    {
        $response = Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
                'Referer' => self::BASE_URL,
                'Origin' => 'https://opendata.citywindsor.ca',
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ])
            ->get(self::BASE_URL);

        if (!$response->successful()) {
            throw new \RuntimeException('Windsor request failed with status '.$response->status());
        }

        return $response->body();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseRows(string $html): array
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $tbody = $xpath->query('//tbody[@id="tableBody"]')->item(0);

        if ($tbody) {
            $rows = $xpath->query('.//tr[contains(@class, "BT_BidAndTender")]', $tbody);
        } else {
            $table = $xpath->query('//table[@id="DataTables_Table_0"]')->item(0);

            if (!$table) {
                return [];
            }

            $rows = $xpath->query('.//tr[contains(@class, "BT_BidAndTender")]', $table);
        }
        $results = [];

        foreach ($rows as $row) {
            $title = $this->textContent($xpath->query('.//span[contains(@class, "h5")]', $row)->item(0));
            if (!$title) {
                continue;
            }

            $openRaw = $this->textContent($xpath->query('.//strong[contains(normalize-space(.), "Open")]/following-sibling::span[1]', $row)->item(0));
            $closeRaw = $this->textContent($xpath->query('.//strong[contains(normalize-space(.), "Close")]/following-sibling::span[1]', $row)->item(0));
            $downloadNode = $xpath->query('.//a[contains(@href, "DownloadTender")]', $row)->item(0);
            $downloadHref = $downloadNode instanceof \DOMElement ? $downloadNode->getAttribute('href') : null;

            $results[] = [
                'title' => $title,
                'solicitation_number' => $this->extractSolicitationNumber($title),
                'open_raw' => $openRaw,
                'close_raw' => $closeRaw,
                'download_href' => $downloadHref,
            ];
        }

        return $results;
    }

    private function upsertProject(array $row): int
    {
        $externalId = $this->extractExternalId($row['download_href'] ?? null) ?? $row['solicitation_number'] ?? $row['title'];

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => (string) $externalId,
        ];

        $openAt = $this->parseWindsorDate($row['open_raw'] ?? null);
        $closeAt = $this->parseWindsorDate($row['close_raw'] ?? null);

        $values = [
            'title' => $row['title'],
            'description' => null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $this->absoluteUrl($row['download_href'] ?? null) ?? self::BASE_URL,
            'location' => self::LOCATION_DEFAULT,
            'published_at' => $openAt,
            'date_available_at' => $openAt,
            'date_closing_at' => $closeAt,
            'solicitation_number' => $row['solicitation_number'],
            'source_status' => 'Open',
            'source_timezone' => 'America/Toronto',
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $row,
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    private function extractExternalId(?string $href): ?string
    {
        if (!$href) {
            return null;
        }

        if (preg_match('/\{([^}]+)\}/', $href, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function extractSolicitationNumber(string $title): ?string
    {
        $parts = explode(',', $title, 2);
        $candidate = trim($parts[0]);

        return $candidate !== '' ? $candidate : null;
    }

    private function absoluteUrl(?string $href): ?string
    {
        if (!$href) {
            return null;
        }

        if (str_starts_with($href, 'http')) {
            return $href;
        }

        return 'https://opendata.citywindsor.ca'.$href;
    }

    private function parseWindsorDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);

        return Carbon::createFromFormat('M d, Y h:i A T', $value, 'America/Toronto');
    }

    private function textContent(?\DOMNode $node): ?string
    {
        if (!$node) {
            return null;
        }

        $text = trim($node->textContent ?? '');

        return $text !== '' ? $text : null;
    }

}
