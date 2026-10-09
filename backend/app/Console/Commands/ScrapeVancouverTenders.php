<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeVancouverTenders extends Command
{
    protected $signature = 'scrape:vancouver-tenders {--status-pages=3}';

    protected $description = 'Ingest City of Vancouver open-for-bid listings from the public Jaggaer (SciQuest) site and refresh closed/awarded statuses.';

    private const SOURCE_SITE_KEY = 'vancouver-tenders';
    private const SOURCE_SITE_NAME = 'City of Vancouver Bids';
    private const LOCATION_DEFAULT = 'Vancouver, BC';
    private const TIMEZONE = 'America/Vancouver';
    private const BASE_URL = 'https://bids.sciquest.com/apps/Router/';
    private const CUSTOMER_ORG = 'CityofVancouver';
    private const PAGE_SIZE = 200;

    private const TAB_OPEN = 'PHX_NAV_SourcingOpenForBid';

    /**
     * Tabs consulted only to update statuses of already-ingested bids.
     */
    private const STATUS_TABS = ['PHX_NAV_SourcingAward', 'PHX_NAV_SourcingClosed'];

    /**
     * Statuses after which a bid no longer changes on the source.
     */
    private const FINAL_STATUSES = ['awarded', 'canceled', 'cancelled'];

    private CookieJar $cookies;

    public function handle(): int
    {
        $itemsFound = 0;
        $itemsUpserted = 0;
        $statusesUpdated = 0;
        $statusPages = max(0, (int) $this->option('status-pages'));
        $this->cookies = new CookieJar();

        $runId = DB::table('scrape_runs')->insertGetId([
            'source_site_key' => self::SOURCE_SITE_KEY,
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            foreach ($this->fetchTabRows(self::TAB_OPEN, PHP_INT_MAX) as $row) {
                $itemsFound++;
                $itemsUpserted += $this->upsertProject($row);
            }

            if ($statusPages > 0) {
                $statusesUpdated = $this->refreshStatuses($statusPages);
            }

            DB::table('scrape_runs')->where('id', $runId)->update([
                'status' => 'success',
                'finished_at' => now(),
                'items_found' => $itemsFound,
                'items_upserted' => $itemsUpserted + $statusesUpdated,
                'message' => "{$statusesUpdated} statuses updated from closed/awarded listings.",
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            DB::table('scrape_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'finished_at' => now(),
                'items_found' => $itemsFound,
                'items_upserted' => $itemsUpserted + $statusesUpdated,
                'message' => $exception->getMessage(),
                'updated_at' => now(),
            ]);

            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} City of Vancouver open bids; {$statusesUpdated} statuses updated.");

        return Command::SUCCESS;
    }

    /**
     * Walk the closed and awarded tabs (newest close date first) and update
     * tracked bids that are not yet final. Never creates new projects.
     */
    private function refreshStatuses(int $maxPages): int
    {
        $resolved = [];
        $pending = Project::query()
            ->where('source_site_key', self::SOURCE_SITE_KEY)
            ->get()
            ->filter(fn (Project $project) => !in_array(strtolower((string) $project->source_status), self::FINAL_STATUSES, true))
            ->keyBy('source_external_id');

        if ($pending->isEmpty()) {
            return 0;
        }

        $oldestClosing = $pending->pluck('date_closing_at')->filter()->min();
        $updated = 0;

        // Awarded first, so a bid listed on both tabs ends up with its final status.
        foreach (self::STATUS_TABS as $tab) {
            $rows = $this->fetchTabRows($tab, $maxPages, $oldestClosing);

            foreach ($rows as $row) {
                $project = $pending->get($this->externalId($row));

                if (!$project || isset($resolved[$project->source_external_id])) {
                    continue;
                }

                $project->fill([
                    'source_status' => $row['source_status'] ?: $project->source_status,
                    'date_closing_at' => $this->parseDate($row['close_raw'] ?? null) ?? $project->date_closing_at,
                ]);

                if ($project->isDirty()) {
                    $project->save();
                    $updated++;
                }

                $resolved[$project->source_external_id] = true;
            }
        }

        return $updated;
    }

    /**
     * Fetch every row of a tab, up to $maxPages pages of PAGE_SIZE. When
     * $stopBefore is given, stop once a page reaches close dates earlier than it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTabRows(string $tab, int $maxPages, ?Carbon $stopBefore = null): array
    {
        $xpath = $this->loadXPath($this->request('GET', 'PublicEvent', [
            'tab' => $tab,
            'CustomerOrg' => self::CUSTOMER_ORG,
        ]));

        $rows = $this->parseRows($xpath);
        $total = $this->extractTotalResults($xpath);

        // The first GET renders 20 rows; re-post the search form for larger pages.
        if ($total === null || $total <= count($rows)) {
            return $rows;
        }

        $rows = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            [$action, $fields] = $this->extractForm($xpath);
            $fields['PageSize'] = (string) self::PAGE_SIZE;
            $fields['PageNum'] = (string) $page;

            $xpath = $this->loadXPath($this->request('POST', $action, $fields));
            $pageRows = $this->parseRows($xpath);

            if (count($pageRows) === 0) {
                break;
            }

            array_push($rows, ...$pageRows);

            if ($page * self::PAGE_SIZE >= $total) {
                break;
            }

            $lastClosing = $this->parseDate(end($pageRows)['close_raw'] ?? null);
            if ($stopBefore && $lastClosing && $lastClosing->lt($stopBefore)) {
                break;
            }

            usleep(500000);
        }

        return $rows;
    }

    private function request(string $method, string $path, array $data): string
    {
        $request = Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
            ])
            ->withOptions([
                'cookies' => $this->cookies,
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ]);

        $response = $method === 'POST'
            ? $request->asForm()->post(self::BASE_URL.$path, $data)
            : $request->get(self::BASE_URL.$path, $data);

        if (!$response->successful()) {
            throw new \RuntimeException('Vancouver tenders request failed with status '.$response->status());
        }

        return $response->body();
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function extractForm(\DOMXPath $xpath): array
    {
        $form = $xpath->query('//form[@name="ActiveForm"]')->item(0);

        if (!$form instanceof \DOMElement) {
            throw new \RuntimeException('Vancouver tenders search form not found.');
        }

        $fields = [];

        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            $type = strtolower($input->getAttribute('type'));
            if (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) {
                continue;
            }

            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        foreach ($xpath->query('.//select[@name]', $form) as $select) {
            $option = $xpath->query('.//option[@selected]', $select)->item(0);
            $fields[$select->getAttribute('name')] = $option instanceof \DOMElement ? $option->getAttribute('value') : '';
        }

        return [$form->getAttribute('action'), $fields];
    }

    private function loadXPath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseRows(\DOMXPath $xpath): array
    {
        $items = $xpath->query('//table[@aria-label="Search Results"]/tbody/tr | //table[@aria-label="Search Results"]/tr');

        if (!$items || $items->length === 0) {
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            $link = $xpath->query('.//a[contains(@class, "btn-link-header")]', $item)->item(0);
            $title = $this->textContent($link);

            if (!$title) {
                continue;
            }

            $fields = [];
            foreach ($xpath->query('.//div[contains(@class, "table-row-layout")]', $item) as $fieldRow) {
                $label = $this->textContent($xpath->query('.//div[contains(@class, "data-row-name")]', $fieldRow)->item(0));
                $value = $xpath->query('.//div[contains(@class, "data-row-content")]', $fieldRow)->item(0);

                if ($label && $value) {
                    $fields[strtolower($label)] = $value;
                }
            }

            $pdfLink = $xpath->query('.//a[starts-with(@id, "SourcingPublicSite_BUTTON_PDF_VIEW")]', $item)->item(0);
            $pdfHref = $pdfLink instanceof \DOMElement ? $pdfLink->getAttribute('href') : '';
            $eventId = preg_match('/Sourcingevent\/(\d+)-event\.pdf/i', $pdfHref, $matches) === 1 ? $matches[1] : null;

            $contact = isset($fields['contact']) ? $this->parseContact($fields['contact'], $xpath) : [null, null];

            $results[] = [
                'event_id' => $eventId,
                'title' => $title,
                'description' => $this->textContent($xpath->query('.//div[contains(@class, "label-mini")]', $item)->item(0)),
                'source_status' => $this->textContent($xpath->query('.//span[contains(@class, "status-badge")]', $item)->item(0)),
                'open_raw' => $this->textContent($fields['open'] ?? null),
                'close_raw' => $this->textContent($fields['close'] ?? null),
                'type' => $this->textContent($fields['type'] ?? null),
                'number' => $this->textContent($fields['number'] ?? null),
                'contact_name' => $contact[0],
                'contact_email' => $contact[1],
            ];
        }

        return $results;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function parseContact(\DOMNode $node, \DOMXPath $xpath): array
    {
        $mailto = $xpath->query('.//a[starts-with(@href, "mailto:")]', $node)->item(0);
        $email = $this->textContent($mailto);
        $text = $this->textContent($node);
        $name = $text !== null && $email !== null ? trim(str_replace($email, '', $text)) : $text;

        return [$name !== '' ? $name : null, $email];
    }

    /**
     * Event links carry per-request AuthTokens and some rows lack an event PDF,
     * so the solicitation number is the stable key.
     */
    private function externalId(array $row): string
    {
        return (string) ($row['number'] ?? $row['event_id'] ?? md5($row['title']));
    }

    private function upsertProject(array $row): int
    {
        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => $this->externalId($row),
        ];

        $openAt = $this->parseDate($row['open_raw'] ?? null);

        $values = [
            'title' => $row['title'],
            'description' => $row['description'],
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => self::BASE_URL.'PublicEvent?tab='.self::TAB_OPEN.'&CustomerOrg='.self::CUSTOMER_ORG,
            'location' => self::LOCATION_DEFAULT,
            'published_at' => $openAt,
            'date_publish_at' => $openAt,
            'date_closing_at' => $this->parseDate($row['close_raw'] ?? null),
            'solicitation_number' => $row['number'],
            'solicitation_type' => $row['type'],
            'buyer_name' => $row['contact_name'],
            'buyer_email' => $row['contact_email'],
            'source_status' => $row['source_status'] ?: 'Open',
            'source_timezone' => self::TIMEZONE,
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $row,
        ];

        $project = Project::firstOrNew($attributes)->fill($values);

        // MySQL reorders JSON keys, which the strict array cast comparison reads as a change.
        if ($project->exists && $project->source_raw == $project->getOriginal('source_raw')) {
            $project->syncOriginalAttribute('source_raw');
        }

        if (!$project->isDirty()) {
            return 0;
        }

        $project->save();

        return 1;
    }

    private function extractTotalResults(\DOMXPath $xpath): ?int
    {
        $text = $this->textContent($xpath->query('//div[contains(@class, "readOnlyPageOf")]')->item(0));

        if ($text === null || preg_match('/of\s+([\d,]+)\s+Results/i', $text, $matches) !== 1) {
            return null;
        }

        return (int) str_replace(',', '', $matches[1]);
    }

    /**
     * Parses values like "2026-11-13, 3:00 p.m. PST" in Vancouver local time,
     * returned in UTC so the stored value matches the app timezone.
     */
    private function parseDate(?string $value): ?Carbon
    {
        if (!$value || preg_match('/(\d{4}-\d{2}-\d{2}),?\s+(\d{1,2}:\d{2})\s*([ap])\.?m\.?/i', $value, $matches) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d g:i A', $matches[1].' '.$matches[2].' '.strtoupper($matches[3]).'M', self::TIMEZONE)->utc();
        } catch (Throwable $exception) {
            return null;
        }
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
