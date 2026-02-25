<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeOttawaMerx extends Command
{
    protected $signature = 'scrape:ottawa-merx {--max-pages=10}';

    protected $description = 'Ingest open solicitations from MERX City of Ottawa (HTML table parsing).';

    private const SOURCE_SITE_KEY = 'merx-ottawa';
    private const SOURCE_SITE_NAME = 'MERX City of Ottawa';
    private const LOCATION_DEFAULT = 'Ottawa, ON';
    private const BASE_URL = 'https://www.merx.com/cityofottawa/solicitations/open-bids';

    public function handle(): int
    {
        $maxPages = (int) $this->option('max-pages');
        $pageNumber = 1;
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
            while ($pageNumber <= $maxPages) {
                $html = $this->fetchPage($pageNumber);
                $rows = $this->parseRows($html);

                if (count($rows) === 0) {
                    break;
                }

                $itemsFound += count($rows);

                foreach ($rows as $row) {
                    $itemsUpserted += $this->upsertProject($row);
                }

                $pageNumber++;
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} MERX Ottawa solicitations.");

        return Command::SUCCESS;
    }

    private function fetchPage(int $pageNumber): string
    {
        $response = Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
                'Referer' => self::BASE_URL,
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ])
            ->get(self::BASE_URL, [
                'sortDirection' => 'DESC',
                'pageNumber' => $pageNumber,
                'pageNumberSelect' => 1,
                'sortBy' => 'solicitationNumber',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('MERX Ottawa request failed with status '.$response->status());
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
        $table = $xpath->query('//table[contains(@class, "sol-table")]')->item(0);

        if (!$table) {
            $table = $xpath->query('//table[contains(@class, "mets-table")]')->item(0);
        }

        if (!$table) {
            return [];
        }

        $rows = $xpath->query('.//tr[contains(@class, "mets-table-row")]', $table);
        $results = [];

        foreach ($rows as $row) {
            if ($this->hasClass($row, 'mets-table-row-empty')) {
                continue;
            }

            $solNumber = $this->textContent($xpath->query('.//div[contains(@class, "sol-num")]', $row)->item(0));
            $titleNode = $xpath->query('.//div[contains(@class, "sol-title")]/a', $row)->item(0);
            $title = $this->textContent($titleNode);
            $href = $titleNode instanceof \DOMElement ? $titleNode->getAttribute('href') : null;

            if (!$solNumber || !$title) {
                continue;
            }

            $location = $this->textContent($xpath->query('.//div[contains(@class, "sol-region")]//span', $row)->item(0));
            $publishedRaw = $this->textContent($xpath->query('.//span[contains(@class, "sol-publication-date")]//span[contains(@class, "date-value")]', $row)->item(0));
            $closingRaw = $this->textContent($xpath->query('.//span[contains(@class, "sol-closing-date")]//span[contains(@class, "date-value")]', $row)->item(0));

            $results[] = [
                'solicitation_number' => $solNumber,
                'title' => $title,
                'href' => $href,
                'location' => $location ?: self::LOCATION_DEFAULT,
                'published_raw' => $publishedRaw,
                'closing_raw' => $closingRaw,
            ];
        }

        return $results;
    }

    private function upsertProject(array $row): int
    {
        $externalId = $this->extractExternalId($row['href'] ?? null) ?? $row['solicitation_number'];

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => $externalId,
        ];

        $publishedAt = $this->parseMerxDate($row['published_raw'] ?? null);
        $closingAt = $this->parseMerxDate($row['closing_raw'] ?? null);

        $values = [
            'title' => $row['title'],
            'description' => null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $this->absoluteUrl($row['href'] ?? null),
            'location' => $row['location'] ?? self::LOCATION_DEFAULT,
            'published_at' => $publishedAt,
            'date_publish_at' => $publishedAt,
            'date_closing_at' => $closingAt,
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

        if (preg_match('/\/(\d{7,})\b/', $href, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function absoluteUrl(?string $href): ?string
    {
        if (!$href) {
            return null;
        }

        if (str_starts_with($href, 'http')) {
            return $href;
        }

        return 'https://www.merx.com'.$href;
    }

    private function parseMerxDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);

        return Carbon::createFromFormat('Y/m/d', $value, 'America/Toronto');
    }

    private function textContent(?\DOMNode $node): ?string
    {
        if (!$node) {
            return null;
        }

        $text = trim($node->textContent ?? '');

        return $text !== '' ? $text : null;
    }

    private function hasClass(\DOMNode $node, string $class): bool
    {
        if (!$node instanceof \DOMElement) {
            return false;
        }

        $classes = preg_split('/\s+/', $node->getAttribute('class') ?? '');

        return in_array($class, $classes, true);
    }

}
