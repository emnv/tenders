<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeMontrealTenders extends Command
{
    protected $signature = 'scrape:montreal-tenders {--max-pages=0}';

    protected $description = "Ingest Montreal call-for-tender listings from the public HTML pages.";

    private const SOURCE_SITE_KEY = 'montreal-tenders';
    private const SOURCE_SITE_NAME = "Montreal Appels d'offres";
    private const LOCATION_DEFAULT = 'Montreal, QC';
    private const BASE_URL = 'https://montreal.ca/avis-dappel-doffres';

    /**
     * @var array<string, int>
     */
    private const MONTHS = [
        'janvier' => 1,
        'fevrier' => 2,
        'mars' => 3,
        'avril' => 4,
        'mai' => 5,
        'juin' => 6,
        'juillet' => 7,
        'aout' => 8,
        'septembre' => 9,
        'octobre' => 10,
        'novembre' => 11,
        'decembre' => 12,
    ];

    public function handle(): int
    {
        $itemsFound = 0;
        $itemsUpserted = 0;
        $pageNumber = 1;
        $configuredMaxPages = max(0, (int) $this->option('max-pages'));
        $detectedTotalPages = null;

        $runId = DB::table('scrape_runs')->insertGetId([
            'source_site_key' => self::SOURCE_SITE_KEY,
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            while (true) {
                if ($configuredMaxPages > 0 && $pageNumber > $configuredMaxPages) {
                    break;
                }

                $html = $this->fetchPage($pageNumber);
                $rows = $this->parseRows($html);

                if ($detectedTotalPages === null) {
                    $detectedTotalPages = $this->extractTotalPages($html);
                }

                if (count($rows) === 0) {
                    break;
                }

                $itemsFound += count($rows);

                foreach ($rows as $row) {
                    $itemsUpserted += $this->upsertProject($row);
                }

                if ($configuredMaxPages === 0 && $detectedTotalPages !== null && $pageNumber >= $detectedTotalPages) {
                    break;
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Montreal tender notices.");

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
                'page' => $pageNumber,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Montreal tenders request failed with status '.$response->status());
        }

        return $response->body();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseRows(string $html): array
    {
        $document = new \DOMDocument();
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $items = $xpath->query('//div[@id="searchResultList"]//li[contains(@class, "list-element")]');

        if (!$items || $items->length === 0) {
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            $link = $xpath->query('.//a[contains(@class, "list-group-item-action")]', $item)->item(0);
            if (!$link instanceof \DOMElement) {
                continue;
            }

            $href = $link->getAttribute('href');
            $title = $this->textContent($xpath->query('.//div[contains(@class, "list-group-item-title")]', $link)->item(0));

            if (!$title || !$href) {
                continue;
            }

            $infoItems = $xpath->query('.//ul[contains(@class, "list-group-infos")]/li[contains(@class, "list-group-info-item")]', $link);

            $results[] = [
                'href' => $href,
                'external_id' => $this->extractExternalId($href),
                'title' => $title,
                'source_status' => $this->textContent($xpath->query('.//span[contains(@class, "badge")]', $link)->item(0)),
                'published_raw' => $infoItems?->length > 0 ? $this->textContent($infoItems->item(0)) : null,
                'location' => $infoItems?->length > 1 ? $this->textContent($infoItems->item(1)) : self::LOCATION_DEFAULT,
            ];
        }

        return $results;
    }

    private function upsertProject(array $row): int
    {
        $externalId = $row['external_id'] ?? md5(($row['href'] ?? '').'|'.($row['title'] ?? ''));

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => (string) $externalId,
        ];

        $publishedAt = $this->parseFrenchDate($row['published_raw'] ?? null);

        $values = [
            'title' => $row['title'],
            'description' => null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $this->absoluteUrl($row['href'] ?? null) ?? self::BASE_URL,
            'location' => $row['location'] ?? self::LOCATION_DEFAULT,
            'published_at' => $publishedAt,
            'date_publish_at' => $publishedAt,
            'solicitation_number' => is_numeric((string) $externalId) ? (string) $externalId : null,
            'source_status' => $row['source_status'] ?: 'Open',
            'source_timezone' => 'America/Toronto',
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $row,
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    private function extractTotalPages(string $html): ?int
    {
        $document = new \DOMDocument();
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//div[@id="searchResultList"]//ul[contains(@class, "pagination")]//*[contains(@class, "page-link")]');

        if (!$links || $links->length === 0) {
            return null;
        }

        $maxPage = null;

        foreach ($links as $link) {
            $text = $this->textContent($link);
            if ($text === null || !preg_match('/^\d+$/', $text)) {
                continue;
            }

            $page = (int) $text;
            $maxPage = $maxPage === null ? $page : max($maxPage, $page);
        }

        return $maxPage;
    }

    private function extractExternalId(string $href): ?string
    {
        if (preg_match('/-(\d+)(?:\/)?(?:\?.*)?$/', $href, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function absoluteUrl(?string $href): ?string
    {
        if (!$href) {
            return null;
        }

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return 'https://montreal.ca'.(str_starts_with($href, '/') ? $href : '/'.$href);
    }

    private function parseFrenchDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        $normalized = $this->normalizeForDate($value);

        if (preg_match('/^(\d{1,2})\s+([a-z]+)\s+(\d{4})$/', $normalized, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $month = self::MONTHS[$matches[2]] ?? null;
        $year = (int) $matches[3];

        if ($month === null) {
            return null;
        }

        return Carbon::create($year, $month, $day, 0, 0, 0, 'America/Toronto');
    }

    private function normalizeForDate(string $value): string
    {
        $value = str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        $value = strtolower($value);

        return strtr($value, [
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'î' => 'i',
            'ï' => 'i',
            'ô' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
        ]);
    }

    private function textContent(?\DOMNode $node): ?string
    {
        if (!$node) {
            return null;
        }

        $text = trim((string) $node->textContent);
        $text = str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : null;
    }
}