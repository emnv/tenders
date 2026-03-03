<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeCanadaBuys extends Command
{
    protected $signature = 'scrape:canadabuys {--pages= : Maximum pages to fetch (leave empty for all pages)} {--items-per-page=200 : Results per page (50, 100, or 200)}';

    protected $description = 'Ingest tender notices from CanadaBuys tender opportunities (Drupal AJAX endpoint).';

    private const SOURCE_SITE_KEY = 'canadabuys-tenders';
    private const SOURCE_SITE_NAME = 'Canada Tender Opportunities';
    private const FRONTEND_URL = 'https://canadabuys.canada.ca/en/tender-opportunities';
    private const AJAX_URL = 'https://canadabuys.canada.ca/en/views/ajax';
    private const SOURCE_TIMEZONE = 'America/Toronto';

    private const DEFAULT_VIEW_PATH = '/node/10653';
    private const DEFAULT_VIEW_DOM_ID = '755667d15d13d99959c6e1e5e6a239c5021d4d4eafc76cd55f4468eb94baf198';
    private const DEFAULT_LIBRARIES = 'eJx1UltywyAMvBCEI3mELNs0GDEgx3FPX_mRJm3iH0bsrh6L8BG-F-cDX-AL7sZHxquAr-43Mp5ZqhTILnPmGxWDA1dKri1ThnjZbwfYxODdHl6wVoNc6CFsA0Tu90aUa0N3rVqdhhYSxEUCVhtSkHM2F27_s8jjyOkVjZDakHqboac1BUknWflKUHDYsvZwQ-e7NE-TGJU5aMvJesDrB9nh6Y34IKVIIyWxHeOkZjqhct5-na0DJLE4EF71tT9rVm8nlFBqtQVyksLxTMQcJWQ7bBt914yK2yA0aisttj2lsB0ItLbZx28ghwYmYV1BjiTkTnBTl6qlnIdK5hZorm47LyO3UzygLaNOfgzinuHBhdStP4Oaiuop7un2gdodNTP5jsv4YuMNMatN_aQFyuJk0M3YHlVlW7r94WZdgWeaV-IHgYM9Zw';

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
            $rawMaxPages = $this->option('pages');
            $maxPages = is_numeric($rawMaxPages) && (int) $rawMaxPages > 0
                ? (int) $rawMaxPages
                : null;
            $itemsPerPage = (int) $this->option('items-per-page');

            if (!in_array($itemsPerPage, [50, 100, 200], true)) {
                $itemsPerPage = 100;
            }

            $prunedCount = $this->pruneNonConstructionProjects();
            if ($prunedCount > 0) {
                $this->info("Pruned {$prunedCount} non-construction CanadaBuys records.");
            }

            $state = $this->bootstrapAjaxState();

            $page = 0;
            while (true) {
                if ($maxPages !== null && $page >= $maxPages) {
                    break;
                }

                $this->info('Fetching CanadaBuys page '.($page + 1).'...');
                $payload = $this->fetchAjaxPage($state, $page, $itemsPerPage);
                $insertHtml = $this->extractInsertHtml($payload);

                if (!$insertHtml) {
                    if ($page === 0) {
                        throw new \RuntimeException('CanadaBuys AJAX payload did not include table HTML.');
                    }

                    break;
                }

                [$rows, $hasNextPage] = $this->parseRowsAndPager($insertHtml);

                if (count($rows) === 0) {
                    break;
                }

                $itemsFound += count($rows);

                foreach ($rows as $row) {
                    $itemsUpserted += $this->upsertProject($row);
                }

                if (!$hasNextPage) {
                    break;
                }

                usleep(350000);
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} CanadaBuys tender notices.");

        return Command::SUCCESS;
    }

    private function bootstrapAjaxState(): array
    {
        $response = Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ])
            ->get(self::FRONTEND_URL);

        if (!$response->successful()) {
            throw new \RuntimeException('CanadaBuys warm-up request failed with status '.$response->status());
        }

        $html = $response->body();

        return [
            'view_path' => $this->extractViewPath($html) ?? self::DEFAULT_VIEW_PATH,
            'view_dom_id' => $this->extractViewDomId($html) ?? self::DEFAULT_VIEW_DOM_ID,
            'libraries' => $this->extractLibraries($html) ?? self::DEFAULT_LIBRARIES,
        ];
    }

    private function fetchAjaxPage(array $state, int $page, int $itemsPerPage): array
    {
        $query = [
            '_wrapper_format' => 'drupal_ajax',
            'view_name' => 'search_opportunities',
            'view_display_id' => 'block_1',
            'view_args' => '',
            'view_path' => $state['view_path'],
            'view_base_path' => '',
            'view_dom_id' => $state['view_dom_id'],
            'pager_element' => 1,
            'page' => sprintf(',%d,0,0', $page),
            '_drupal_ajax' => 1,
            'items_per_page' => $itemsPerPage,
            'ajax_page_state' => [
                'theme' => 'eps_wxt_bootstrap',
                'theme_token' => '',
                'libraries' => $state['libraries'],
            ],
        ];

        $response = Http::timeout(30)
            ->retry(3, 700)
            ->withHeaders([
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => self::FRONTEND_URL,
                'Origin' => 'https://canadabuys.canada.ca',
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ])
            ->get(self::AJAX_URL, $query);

        if (!$response->successful()) {
            throw new \RuntimeException('CanadaBuys AJAX request failed with status '.$response->status());
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new \RuntimeException('CanadaBuys AJAX returned a non-JSON response.');
        }

        return $json;
    }

    private function extractInsertHtml(array $payload): ?string
    {
        foreach ($payload as $command) {
            if (!is_array($command)) {
                continue;
            }

            if (($command['command'] ?? null) !== 'insert') {
                continue;
            }

            $data = $command['data'] ?? null;

            if (!is_string($data) || trim($data) === '') {
                continue;
            }

            if (str_contains($data, 'views-view-table')) {
                return $data;
            }
        }

        return null;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function parseRowsAndPager(string $html): array
    {
        $document = new \DOMDocument();
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//table[contains(@class, "views-view-table")]/tbody/tr');

        $rows = [];

        foreach ($nodes as $node) {
            $cells = $xpath->query('./td', $node);
            if (!$cells || $cells->length < 5) {
                continue;
            }

            $titleCell = $cells->item(0);
            $titleLink = $xpath->query('.//a[1]', $titleCell)->item(0);
            $title = $this->extractTitle($titleLink, $titleCell);

            if (!$title) {
                continue;
            }

            $href = $titleLink instanceof \DOMElement ? $titleLink->getAttribute('href') : null;
            $sourceUrl = $this->absoluteUrl($href) ?? self::FRONTEND_URL;
            $title = $this->maybeResolveFullTitle($title, $sourceUrl);
            $category = $this->cleanText($cells->item(1)?->textContent);

            if (!$this->isConstructionCategory($category)) {
                continue;
            }

            $openRaw = $this->cleanText($cells->item(2)?->textContent);
            $closingRaw = $this->cleanText($cells->item(3)?->textContent);
            $organization = $this->cleanText($cells->item(4)?->textContent);

            $externalId = $this->extractExternalId($href) ?? sha1($title.'|'.$closingRaw.'|'.$organization);

            $rows[] = [
                'source_external_id' => $externalId,
                'title' => $title,
                'source_url' => $sourceUrl,
                'high_level_category' => $category,
                'date_publish_raw' => $openRaw,
                'date_closing_raw' => $closingRaw,
                'organization' => $organization,
                'source_status' => 'Open',
            ];
        }

        $hasNextPage = (bool) $xpath->query('//div[contains(@class, "js-pager__items")]//a[@rel="next"]')->length;

        return [$rows, $hasNextPage];
    }

    private function extractTitle(?\DOMNode $titleLink, ?\DOMNode $titleCell): ?string
    {
        if ($titleLink instanceof \DOMElement) {
            $attributeCandidates = [
                $titleLink->getAttribute('title'),
                $titleLink->getAttribute('aria-label'),
                $titleLink->getAttribute('data-original-title'),
            ];

            foreach ($attributeCandidates as $candidate) {
                $clean = $this->cleanText($candidate);
                if ($clean) {
                    return $clean;
                }
            }

            $text = $this->cleanText($titleLink->textContent);
            if ($text) {
                return $text;
            }
        }

        return $this->cleanText($titleCell?->textContent);
    }

    private function upsertProject(array $row): int
    {
        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => (string) $row['source_external_id'],
        ];

        $publishAt = $this->parseDate($row['date_publish_raw'] ?? null);
        $closingAt = $this->parseDate($row['date_closing_raw'] ?? null);

        $values = [
            'title' => $row['title'] ?? 'Untitled tender notice',
            'description' => null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $row['source_url'] ?? self::FRONTEND_URL,
            'location' => $row['organization'] ?? 'Canada',
            'published_at' => $publishAt,
            'date_publish_at' => $publishAt,
            'date_available_at' => $publishAt,
            'date_closing_at' => $closingAt,
            'solicitation_number' => $row['source_external_id'] ?? null,
            'high_level_category' => $row['high_level_category'] ?? null,
            'buyer_name' => $row['organization'] ?? null,
            'source_status' => $row['source_status'] ?? 'Open',
            'source_scope' => 'Tender notices',
            'source_timezone' => self::SOURCE_TIMEZONE,
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

        if (preg_match('/(\d{4}\/\d{2}\/\d{2})/', $value, $matches) !== 1) {
            return null;
        }

        $year = (int) substr($matches[1], 0, 4);
        if ($year < 1900 || $year > 2037) {
            return null;
        }

        return Carbon::createFromFormat('Y/m/d', $matches[1], self::SOURCE_TIMEZONE)->startOfDay();
    }

    private function absoluteUrl(?string $href): ?string
    {
        if (!$href) {
            return null;
        }

        if (str_starts_with($href, 'http')) {
            return $href;
        }

        if (str_starts_with($href, '/')) {
            return 'https://canadabuys.canada.ca'.$href;
        }

        return 'https://canadabuys.canada.ca/'.$href;
    }

    private function extractExternalId(?string $href): ?string
    {
        if (!$href) {
            return null;
        }

        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $lastSegment = basename($path);
        $lastSegment = trim($lastSegment);

        return $lastSegment !== '' ? $lastSegment : null;
    }

    private function extractViewPath(string $html): ?string
    {
        if (preg_match('/"view_path"\s*:\s*"([^"]+)"/', $html, $matches) === 1) {
            return trim(stripslashes($matches[1]));
        }

        return null;
    }

    private function extractViewDomId(string $html): ?string
    {
        if (preg_match('/view-display-id-block_1[^>]*js-view-dom-id-([a-f0-9]{20,})/i', $html, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/js-view-dom-id-([a-f0-9]{20,})/i', $html, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractLibraries(string $html): ?string
    {
        if (preg_match('/"ajaxPageState"\s*:\s*\{[^}]*"libraries"\s*:\s*"([^"]+)"/s', $html, $matches) === 1) {
            return trim(stripslashes($matches[1]));
        }

        return null;
    }

    private function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized));
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        return $normalized;
    }

    private function maybeResolveFullTitle(?string $title, string $sourceUrl): ?string
    {
        $title = $this->cleanText($title);
        if (!$title) {
            return $title;
        }

        if (!preg_match('/(?:\.{3}|…)$|\b\.\.\.$/u', $title)) {
            return $title;
        }

        try {
            $response = Http::timeout(20)
                ->retry(2, 400)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer' => self::FRONTEND_URL,
                    'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
                ])
                ->withOptions([
                    'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
                ])
                ->get($sourceUrl);

            if (!$response->successful()) {
                return $title;
            }

            $html = $response->body();

            $metaTitle = null;
            if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/iu', $html, $matches) === 1) {
                $metaTitle = $this->cleanText($matches[1] ?? null);
            }

            if (!$metaTitle && preg_match('/<h1[^>]*>(.*?)<\/h1>/isu', $html, $matches) === 1) {
                $metaTitle = $this->cleanText(strip_tags($matches[1] ?? ''));
            }

            if ($metaTitle && mb_strlen($metaTitle) > mb_strlen($title) && !preg_match('/(?:\.{3}|…)$|\b\.\.\.$/u', $metaTitle)) {
                return $metaTitle;
            }
        } catch (Throwable $exception) {
            return $title;
        }

        return $title;
    }

    private function isConstructionCategory(?string $category): bool
    {
        if (!$category) {
            return false;
        }

        return str_contains(strtolower($category), 'construction');
    }

    private function pruneNonConstructionProjects(): int
    {
        return Project::query()
            ->where('source_site_key', self::SOURCE_SITE_KEY)
            ->where(function ($query) {
                $query->whereNull('high_level_category')
                    ->orWhereRaw('LOWER(high_level_category) NOT LIKE ?', ['%construction%']);
            })
            ->delete();
    }
}
