<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeSaskTenders extends Command
{
    protected $signature = 'scrape:sasktenders {--pages=10 : Maximum pages to fetch}';

    protected $description = 'Ingest open competitions from Saskatchewan Tenders (HTML form postback).';

    private const SOURCE_SITE_KEY = 'sasktenders';
    private const SOURCE_SITE_NAME = 'Saskatchewan Tenders';
    private const BASE_URL = 'https://sasktenders.ca/content/public/Search.aspx';
    private const DEFAULT_LOCATION = 'Saskatchewan';

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

            $cookieJar = new CookieJar();
            $client = Http::timeout(40)
                ->retry(3, 500)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Origin' => 'https://sasktenders.ca',
                    'Referer' => self::BASE_URL,
                    'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
                ])
                ->withOptions([
                    'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
                    'cookies' => $cookieJar,
                ]);

            $page = 1;
            $response = $client->get(self::BASE_URL);

            if (!$response->successful()) {
                throw new \RuntimeException('SaskTenders initial request failed with status '.$response->status());
            }

            $html = $response->body();

            do {
                $itemsFound += $this->parseAndUpsert($html, $itemsUpserted);

                $hiddenFields = $this->extractHiddenFields($html);
                $totalRows = (int) ($hiddenFields['ctl00$ContentPlaceHolder1$hdnNumberOfRows'] ?? 0);
                $pageSize = (int) ($hiddenFields['ctl00$ContentPlaceHolder1$hdnPageSize'] ?? 50);
                $currentPage = (int) ($hiddenFields['ctl00$ContentPlaceHolder1$hdnCurrentPage'] ?? $page);
                $totalPages = $pageSize > 0 ? (int) ceil($totalRows / $pageSize) : 1;

                if ($currentPage >= $totalPages || $page >= $maxPages) {
                    break;
                }

                $payload = $hiddenFields;
                $payload['__EVENTTARGET'] = 'ctl00$ContentPlaceHolder1$lnkNextPage';
                $payload['__EVENTARGUMENT'] = '';
                $payload['__LASTFOCUS'] = '';

                if (array_key_exists('ctl00$ToolkitScriptManager1', $payload)) {
                    $payload['ctl00$ToolkitScriptManager1'] = 'ctl00$ContentPlaceHolder1$upnlSearchResults|ctl00$ContentPlaceHolder1$lnkNextPage';
                }

                $response = $client
                    ->asForm()
                    ->post(self::BASE_URL, $payload);

                if (!$response->successful()) {
                    throw new \RuntimeException('SaskTenders page request failed with status '.$response->status());
                }

                $html = $response->body();
                $page++;
                usleep(300000);
            } while (true);

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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} SaskTenders competitions.");

        return Command::SUCCESS;
    }

    private function extractHiddenFields(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $inputs = $xpath->query("//input[@type='hidden' and @name]");
        $fields = [];

        foreach ($inputs as $input) {
            $name = $input->getAttribute('name');
            $value = $input->getAttribute('value');
            $fields[$name] = $value;
        }

        return $fields;
    }

    private function parseAndUpsert(string $html, int &$itemsUpserted): int
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $headers = $xpath->query("//div[contains(@class,'HeaderAccordionPlusFormat')]");
        $count = 0;

        foreach ($headers as $header) {
            $table = $xpath->query(".//table[contains(@class,'ContentAccordionFormat') or contains(@class,'HeadertAlternateAccordionFormat')]", $header)->item(0);
            if (!$table) {
                continue;
            }

            $cells = $xpath->query('.//td', $table);
            if ($cells->length < 7) {
                continue;
            }

            $title = $this->cleanText($cells->item(1)->textContent ?? '');
            $organization = $this->cleanText($cells->item(2)->textContent ?? '');
            $competitionNumber = $this->cleanText($cells->item(3)->textContent ?? '');
            $openDateRaw = $this->cleanText($cells->item(4)->textContent ?? '');
            $closeDateRaw = $this->cleanText($cells->item(5)->textContent ?? '');
            $status = $this->cleanText($cells->item(6)->textContent ?? '');

            if ($title === '' || $competitionNumber === '') {
                continue;
            }

            $detailDiv = $xpath->query("following-sibling::div[contains(@class,'ContentAccordionFormat_SearchPage')][1]", $header)->item(0);
            $competitionId = null;
            $sourceUrl = self::BASE_URL;

            if ($detailDiv) {
                $link = $xpath->query(".//a[contains(@href,'print.aspx?competitionId=')]", $detailDiv)->item(0);
                if ($link) {
                    $href = $link->getAttribute('href');
                    $competitionId = $this->extractQueryParam($href, 'competitionId');
                    if ($competitionId) {
                        $sourceUrl = 'https://sasktenders.ca/content/public/print.aspx?competitionId=' . $competitionId;
                    }
                }
            }

            $values = [
                'title' => $title,
                'description' => null,
                'source_site_name' => self::SOURCE_SITE_NAME,
                'source_url' => $sourceUrl,
                'location' => $organization !== '' ? ($organization . ', SK') : self::DEFAULT_LOCATION,
                'published_at' => $this->parseDate($openDateRaw),
                'date_publish_at' => $this->parseDate($openDateRaw),
                'date_closing_at' => $this->parseDate($closeDateRaw),
                'solicitation_number' => $competitionNumber,
                'buyer_name' => $organization ?: null,
                'source_status' => $status ?: null,
                'source_timezone' => 'America/Regina',
                'is_manual_entry' => false,
                'is_featured' => false,
                'source_raw' => [
                    'competition_id' => $competitionId,
                    'competition_number' => $competitionNumber,
                    'organization' => $organization,
                    'open_date' => $openDateRaw,
                    'close_date' => $closeDateRaw,
                    'status' => $status,
                ],
            ];

            $attributes = [
                'source_site_key' => self::SOURCE_SITE_KEY,
                'source_external_id' => $competitionId ?: $competitionNumber,
            ];

            // Only create new projects if they have an open status.
            // Always update existing projects so status changes are captured.
            $exists = Project::where($attributes)->exists();

            if (!$exists && !$this->isOpenStatus($status)) {
                $count++;
                continue;
            }

            $project = Project::updateOrCreate($attributes, $values);
            $itemsUpserted += $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
            $count++;
        }

        return $count;
    }

    private function parseDate(string $value): ?Carbon
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', $trimmed);

        try {
            return Carbon::parse($clean, 'America/Regina');
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function cleanText(string $value): string
    {
        $clean = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        $clean = preg_replace('/\s+/', ' ', $clean);

        return trim($clean);
    }

    /**
     * Determine whether a status string represents an open/active competition.
     */
    private function isOpenStatus(?string $status): bool
    {
        if ($status === null || trim($status) === '') {
            return true;
        }

        $openStatuses = ['open', 'active', 'published'];

        return in_array(strtolower(trim($status)), $openStatuses, true);
    }

    private function extractQueryParam(string $href, string $key): ?string
    {
        $parts = parse_url($href);
        if (!isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $params);

        return $params[$key] ?? null;
    }
}
