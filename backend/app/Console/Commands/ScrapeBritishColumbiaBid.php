<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeBritishColumbiaBid extends Command
{
    protected $signature = 'scrape:bc-bid
        {--pages= : Maximum pages to fetch (omit for all)}
        {--expected-count= : Expected total records (override total count)}
        {--session= : ASP.NET_SessionId cookie value}
        {--csrf= : CSRFToken value}
        {--cookie-header= : Full Cookie header captured from browser (optional)}
        {--playwright : Use Playwright to fetch HTML (fallback if no credentials)}';

    protected $description = 'Ingest opportunities from BC Bid. Provide --session and --csrf from a browser session for cron use.';

    private const SOURCE_SITE_KEY = 'bc-bid';
    private const SOURCE_SITE_NAME = 'British Columbia Bid';
    private const BASE_HOST = 'https://bcbid.gov.bc.ca';
    private const PAGE_URL = 'https://bcbid.gov.bc.ca/page.aspx/en/rfp/request_browse_public';
    private const AJAX_URL = 'https://bcbid.gov.bc.ca/ajax.aspx/en/rfp/request_browse_public';

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
            $pagesOption = $this->option('pages');
            $maxPages = $pagesOption !== null && $pagesOption !== '' ? max(1, (int) $pagesOption) : PHP_INT_MAX;
            $usePlaywright = (bool) $this->option('playwright');
            $expectedCount = $this->option('expected-count');
            $expectedCount = $expectedCount !== null && $expectedCount !== '' ? (int) $expectedCount : null;

            $settings = $this->getScraperSettings();

            $sessionFromCli = $this->option('session');
            $csrfFromCli = $this->option('csrf');

            // Check for session credentials (CLI options take precedence over dashboard settings)
            $sessionId = $sessionFromCli ?: ($settings['session_id'] ?? null);
            $csrfToken = $csrfFromCli ?: ($settings['csrf_token'] ?? null);
            $cookieHeader = $this->option('cookie-header') ?: ($settings['cookie_header'] ?? null);
            $expectedCount = $expectedCount ?? (isset($settings['expected_count']) ? (int) $settings['expected_count'] : null);

            // If a full cookie header is provided, prefer credentials from it so
            // session/csrf remain consistent with each other.
            if ($cookieHeader) {
                $cookieSession = $this->extractCookieValue($cookieHeader, 'ASP.NET_SessionId');
                $cookieCsrf = $this->extractCookieValue($cookieHeader, 'CSRFToken');

                if ($cookieSession) {
                    $sessionId = $cookieSession;
                }

                if ($cookieCsrf) {
                    $csrfToken = $cookieCsrf;
                }
            }

            // If we have credentials, use direct HTTP approach (cron-friendly)
            if ($sessionId && $csrfToken) {
                $this->info('Using direct HTTP with provided session credentials...');
                $pages = $this->fetchWithCredentials($sessionId, $csrfToken, $maxPages, $expectedCount, $cookieHeader);

                foreach ($pages as $index => $html) {
                    if (env('SCRAPER_DEBUG_BC_BID')) {
                        file_put_contents(storage_path('app/bc-bid-page-'.$index.'.html'), $html);
                    }

                    $itemsFound += $this->parseAndUpsert($html, $itemsUpserted);
                }
            } elseif ($usePlaywright) {
                // Fallback to Playwright (requires manual browser check)
                $this->warn('No session credentials provided. Using Playwright (may require manual intervention)...');
                $pages = $this->fetchPlaywrightPages($maxPages);

                foreach ($pages as $index => $html) {
                    if (str_contains($html, 'Browser check: BC Bid')) {
                        throw new \RuntimeException('Playwright still hit the browser check. Provide --session and --csrf options from a valid browser session.');
                    }
                    if (str_contains($html, 'scriptToLoad') && !str_contains($html, 'body_x_grid_grd')) {
                        throw new \RuntimeException('Playwright returned a script bootstrap response. Provide --session and --csrf options from a valid browser session.');
                    }

                    if (env('SCRAPER_DEBUG_BC_BID')) {
                        file_put_contents(storage_path('app/bc-bid-playwright-'.$index.'.html'), $html);
                    }

                    $itemsFound += $this->parseAndUpsert($html, $itemsUpserted);
                }
            } else {
                throw new \RuntimeException(
                    "No BC Bid credentials available.\n\n".
                    "Provide --session and --csrf explicitly, or save session_id/csrf_token in scraper settings."
                );
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} BC Bid opportunities.");

        return Command::SUCCESS;
    }

    /**
     * Fetch pages using direct HTTP requests with provided session credentials.
     * This is the cron-friendly approach that bypasses the browser check.
     */
    private function fetchWithCredentials(string $sessionId, string $csrfToken, int $maxPages, ?int $expectedCount = null, ?string $cookieHeader = null): array
    {
        $pages = [];
        $userAgent = env('BC_BID_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36');

        $sessionId = $this->normalizeCredentialValue($sessionId, 'ASP.NET_SessionId');
        $csrfToken = $this->normalizeCredentialValue($csrfToken, 'CSRFToken');

        // Cookie header
        $cookie = $this->buildCredentialCookieHeader($sessionId, $csrfToken, $cookieHeader);

        $baseHeaders = [
            'Accept' => '*/*',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Connection' => 'keep-alive',
            'Cookie' => $cookie,
            'Host' => 'bcbid.gov.bc.ca',
            'IV-Ajax' => 'AjaxPost=true',
            'IV-AjaxControl' => 'updatepanel',
            'Origin' => 'https://bcbid.gov.bc.ca',
            'Referer' => self::PAGE_URL,
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => $userAgent,
            'X-Requested-With' => 'XMLHttpRequest',
            'mode' => 'html',
        ];

        $client = Http::timeout(60)
            ->retry(3, 1000)
            ->withHeaders($baseHeaders)
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ]);

        // Build the base form payload
        $basePayload = $this->buildBasePayload($csrfToken);

        // Fetch total record count from ajax endpoint when possible
        $totalRecords = $expectedCount ?? $this->fetchTotalRecordsFromAjax($client, $basePayload);

        // Fetch full page once to get total record count (fallback)
        $fullPageClient = Http::timeout(60)
            ->retry(3, 1000)
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Connection' => 'keep-alive',
                'Cookie' => $cookie,
                'Host' => 'bcbid.gov.bc.ca',
                'Referer' => self::PAGE_URL,
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'same-origin',
                'Upgrade-Insecure-Requests' => '1',
                'User-Agent' => $userAgent,
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ]);

        $fullPageResponse = $fullPageClient->get(self::PAGE_URL);
        if ($fullPageResponse->successful()) {
            $fullPageHtml = $fullPageResponse->body();
            $totalRecords = $totalRecords ?? $this->extractTotalRecords($fullPageHtml);

            if (str_contains($fullPageHtml, 'Browser check: BC Bid') || str_contains($fullPageHtml, 'Access Denied')) {
                throw new \RuntimeException('Session credentials are invalid or expired. Please provide fresh credentials from a browser session.');
            }
        }

        // Fetch first page (page index 1)
        $this->info('Fetching page 1...');
        $html = $this->fetchAjaxPageDirect($client, $basePayload, 1);

        if (str_contains($html, 'Browser check: BC Bid') || str_contains($html, 'Access Denied')) {
            throw new \RuntimeException('Session credentials are invalid or expired. Please provide fresh credentials from a browser session.');
        }

        $pages[] = $html;

        // Extract max page index from response
        $rowsOnPage = $this->countRowsOnPage($html);
        $maxPagesAvailable = $this->maxPageIndexFromHtml($html, $totalRecords, $rowsOnPage);
        if (env('SCRAPER_DEBUG_BC_BID')) {
            $this->info("BC Bid debug: totalRecords={$totalRecords}, rowsOnPage={$rowsOnPage}, computedPages={$maxPagesAvailable}");
        }
        $this->info("Total pages available: {$maxPagesAvailable}");

        // Extract current row IDs for subsequent requests
        $rowIds = $this->extractRowIds($html);

        // Fetch remaining pages
        for ($pageIndex = 2; $pageIndex <= min($maxPages, $maxPagesAvailable); $pageIndex++) {
            $this->info("Fetching page {$pageIndex}...");

            // Update payload with current state
            $pagePayload = $this->buildPagePayload($basePayload, $pageIndex, $rowIds);
            $html = $this->fetchAjaxPageDirect($client, $pagePayload, $pageIndex);
            $pages[] = $html;

            // Update row IDs for next request
            $rowIds = $this->extractRowIds($html);

            usleep(500000); // 500ms delay between requests
        }

        return $pages;
    }

    private function normalizeCredentialValue(string $value, string $key): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $trimmed;
        }

        if (preg_match('/'.preg_quote($key, '/').'=([^;\s]+)/i', $trimmed, $matches) === 1) {
            $trimmed = $matches[1];
        }

        return urldecode(trim($trimmed, " \t\n\r\0\x0B\"'"));
    }

    private function buildCredentialCookieHeader(string $sessionId, string $csrfToken, ?string $cookieHeader): string
    {
        $cookie = trim((string) $cookieHeader);

        if ($cookie !== '') {
            if (!str_contains(strtolower($cookie), 'asp.net_sessionid=')) {
                $cookie .= '; ASP.NET_SessionId='.$sessionId;
            }

            if (!str_contains(strtolower($cookie), 'csrftoken=')) {
                $cookie .= '; CSRFToken='.$csrfToken;
            }

            return $cookie;
        }

        return "ASP.NET_SessionId={$sessionId}; CSRFToken={$csrfToken}";
    }

    private function extractCookieValue(string $cookieHeader, string $key): ?string
    {
        if (preg_match('/(?:^|;\s*)'.preg_quote($key, '/').'=([^;]+)/i', $cookieHeader, $matches) !== 1) {
            return null;
        }

        return $this->normalizeCredentialValue($matches[1], $key);
    }

    /**
     * Build the base form payload for AJAX requests.
     */
    private function buildBasePayload(string $csrfToken): array
    {
        return [
            '__isSecurePage' => 'true',
            'hdnUserValue' => 'body_x_txtQuery,body_x_selFamily,body_x_selNtypeCode,body_x_selSrfxCode,body_x_selBpmIdOrgaLevelOrgaNode,body_x_txtRfpBeginDate,body_x_selPtypeCode,body_x_selRtgrouCode,body_x_txtRfpEndDate,body_x_selRfpIdAreaLevelAreaNode,body_x_txtRfpRfxId_1',
            '__LASTFOCUS' => '',
            '__EVENTTARGET' => 'body_x_grid_grd',
            '__EVENTARGUMENT' => 'Page|1',
            'HTTP_RESOLUTION' => '',
            'REQUEST_METHOD' => 'GET',
            'header:x:prxHeaderLogInfo:x:ContrastModal:chkContrastTheme_radio' => 'true',
            'header:x:prxHeaderLogInfo:x:ContrastModal:chkContrastTheme' => 'True',
            'x_headaction' => '',
            'x_headloginName' => '',
            'header:x:prxHeaderLogInfo:x:ContrastModal:chkPassiveNotification' => '0',
            'proxyActionBar:x:txtWflRefuseMessage' => '',
            'hdnMandatory' => '0',
            'hdnWflAction' => '',
            'body:_ctl0' => '',
            'body:x:txtQuery' => '',
            'body:x:txtRfpRfxId_1' => '',
            'body_x_selSrfxCode_text' => '',
            'body:x:selSrfxCode' => 'val', // "Open" status filter
            'body_x_selRtgrouCode_text' => '',
            'body:x:selRtgrouCode' => '',
            'body_x_selNtypeCode_text' => '',
            'body:x:selNtypeCode' => '',
            'body_x_selRfpIdAreaLevelAreaNode_text' => '',
            'body:x:selRfpIdAreaLevelAreaNode' => '',
            'body:x:txtRfpBeginDate' => '',
            'body:x:txtRfpBeginDatemax' => '',
            'body_x_selBpmIdOrgaLevelOrgaNode_text' => '',
            'body:x:selBpmIdOrgaLevelOrgaNode' => '',
            'body_x_selPtypeCode_text' => '',
            'body:x:selPtypeCode' => '',
            'body_x_selFamily_text' => '',
            'body:x:selFamily' => '',
            'body:x:txtRfpEndDate' => '',
            'body:x:txtRfpEndDatemax' => '',
            'body:x:prxFilterBar:x:hdnResetFilterUrlbody_x_prxFilterBar_x_cmdRazBtn' => '',
            'hdnSortExpressionbody_x_grid_grd' => '',
            'hdnSortDirectionbody_x_grid_grd' => '',
            'hdnCurrentPageIndexbody_x_grid_grd' => '1',
            'hdnRowCountbody_x_grid_grd' => '151',
            'maxpageindexbody_x_grid_grd' => '10',
            'ajaxrowsiscountedbody_x_grid_grd' => 'False',
            'CSRFToken' => $csrfToken,
        ];
    }

    /**
     * Build payload for a specific page request.
     */
    private function buildPagePayload(array $basePayload, int $pageIndex, array $rowIds): array
    {
        $payload = $basePayload;
        $payload['__LASTFOCUS'] = 'body_x_grid_gridPagerBtnNextPage';
        $payload['__EVENTARGUMENT'] = 'Page|'.$pageIndex;
        $payload['hdnCurrentPageIndexbody_x_grid_grd'] = (string) ($pageIndex - 1);

        // Add row checkboxes for current page rows
        foreach ($rowIds as $rowId) {
            $payload["body:x:grid:grd:tr_{$rowId}:ctrl_colRfpPlanholdersUsed"] = 'False';
        }

        return $payload;
    }

    /**
     * Extract row IDs from HTML response.
     */
    private function extractRowIds(string $html): array
    {
        $ids = [];
        if (preg_match_all('/data-id="(\d+)"/', $html, $matches)) {
            $ids = $matches[1];
        }
        return $ids;
    }

    /**
     * Fetch a single AJAX page using direct HTTP.
     */
    private function fetchAjaxPageDirect($client, array $payload, int $pageIndex): string
    {
        $response = $client
            ->asForm()
            ->post(self::AJAX_URL.'?ivControlUIDsAsync=body:x:grid:upgrid&asyncmodulename=rfp&asyncpagename=request_browse_public', $payload);

        if (!$response->successful()) {
            throw new \RuntimeException("BC Bid page {$pageIndex} request failed with status ".$response->status());
        }

        return $this->normalizeAjaxBody($response->body());
    }

    private function normalizeAjaxBody(string $body): string
    {
        if (str_contains($body, '<table') || str_contains($body, '<div')) {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (is_string($decoded)) {
            return $decoded;
        }

        if (is_array($decoded)) {
            foreach ($decoded as $value) {
                if (is_string($value) && str_contains($value, '<table')) {
                    return $value;
                }
            }
        }

        if (str_contains($body, '\\u003c')) {
            return stripcslashes($body);
        }

        return $body;
    }

    private function extractFormFields(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $inputs = $xpath->query("//input[@name]");
        $fields = [];

        foreach ($inputs as $input) {
            $name = $input->getAttribute('name');
            $value = $input->getAttribute('value');
            $fields[$name] = $value;
        }

        return $fields;
    }

    private function maxPageIndexFromHtml(string $html, ?int $totalRecords = null, int $rowsOnPage = 0): int
    {
        if ($totalRecords !== null && $rowsOnPage > 0) {
            return max(1, (int) ceil($totalRecords / $rowsOnPage));
        }

        $maxIndex = null;

        if (preg_match('/name="maxpageindexbody_x_grid_grd"[^>]*value="(\d+)"/i', $html, $matches) === 1) {
            $maxIndex = (int) $matches[1];
        } elseif (preg_match('/value="(\d+)"[^>]*name="maxpageindexbody_x_grid_grd"/i', $html, $matches) === 1) {
            $maxIndex = (int) $matches[1];
        }

        if ($maxIndex === null) {
            if (preg_match_all('/data-page-index="(\d+)"/i', $html, $matches) && !empty($matches[1])) {
                $indices = array_map('intval', $matches[1]);
                $maxIndex = max($indices);
            }
        }

        if ($maxIndex !== null) {
            return max(1, $maxIndex + 1);
        }

        $rowCount = null;
        if (preg_match('/name="hdnRowCountbody_x_grid_grd"[^>]*value="(\d+)"/i', $html, $matches) === 1) {
            $rowCount = (int) $matches[1];
        }

        if ($rowCount !== null && $rowsOnPage > 0) {
            return max(1, (int) ceil($rowCount / $rowsOnPage));
        }

        return 1;
    }

    private function extractTotalRecords(string $html): ?int
    {
        if (preg_match('/(\d+)\s*Record\(s\)/i', $html, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/name="hdnRowCountbody_x_grid_grd"[^>]*value="(\d+)"/i', $html, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function countRowsOnPage(string $html): int
    {
        if (preg_match_all('/<tr[^>]*data-id="\d+"/i', $html, $matches)) {
            return count($matches[0]);
        }

        return 0;
    }

    private function fetchTotalRecordsFromAjax($client, array $basePayload): ?int
    {
        try {
            $payload = $basePayload;
            $payload['__EVENTARGUMENT'] = 'GetCount';
            $payload['__EVENTTARGET'] = 'body_x_grid_grd';
            $payload['ajaxmaxpageindexbody_x_grid_grd'] = $payload['maxpageindexbody_x_grid_grd'] ?? '0';

            $response = $client
                ->asForm()
                ->post(self::AJAX_URL.'?ivControlUIDsAsync=body:x:grid:upgrid&asyncmodulename=rfp&asyncpagename=request_browse_public', $payload);

            if (!$response->successful()) {
                return null;
            }

            $body = trim($response->body());

            if ($body === '' || str_contains($body, 'Browser check: BC Bid') || str_contains($body, 'Access Denied')) {
                return null;
            }

            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['count'])) {
                return (int) $decoded['count'];
            }

            if (preg_match('/\bcount\s*:\s*(\d+)/i', $body, $matches) === 1) {
                return (int) $matches[1];
            }

            if (preg_match('/(\d+)\s*Record\(s\)/i', $body, $matches) === 1) {
                return (int) $matches[1];
            }
        } catch (Throwable $exception) {
            return null;
        }

        return null;
    }

    private function parseAndUpsert(string $html, int &$itemsUpserted): int
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $rows = $xpath->query("//table[@id='body_x_grid_grd']//tr[@data-id]");
        $count = 0;

        foreach ($rows as $row) {
            $externalId = $row->getAttribute('data-id');
            $cells = $xpath->query('./td', $row);

            if ($cells->length < 12) {
                continue;
            }

            $status = $this->cleanText($cells->item(0)->textContent ?? '');
            $opportunityId = $this->cleanText($cells->item(1)->textContent ?? '');
            $title = $this->cleanText($cells->item(2)->textContent ?? '');
            $commodities = $this->cleanText($cells->item(3)->textContent ?? '');
            $type = $this->cleanText($cells->item(4)->textContent ?? '');
            $issueDateRaw = $this->cleanText($cells->item(5)->textContent ?? '');
            $closeDateRaw = $this->cleanText($cells->item(6)->textContent ?? '');
            $lastUpdatedRaw = $this->cleanText($cells->item(9)->textContent ?? '');
            $orgIssuedBy = $this->cleanText($cells->item(10)->textContent ?? '');
            $orgIssuedFor = $this->cleanText($cells->item(11)->textContent ?? '');

            if ($externalId === '' || $title === '') {
                continue;
            }

            $link = $xpath->query('.//a[@href]', $cells->item(1))->item(0);
            $href = $link ? $link->getAttribute('href') : null;
            $sourceUrl = $href ? $this->absoluteUrl($href) : self::PAGE_URL;

            $location = $orgIssuedFor !== '' ? $orgIssuedFor : ($orgIssuedBy !== '' ? $orgIssuedBy : 'British Columbia');

            $attributes = [
                'source_site_key' => self::SOURCE_SITE_KEY,
                'source_external_id' => (string) $externalId,
            ];

            // Only create new projects if they have an open status.
            // Always update existing projects so status changes are captured.
            $exists = Project::where($attributes)->exists();

            if (!$exists && !$this->isOpenStatus($status)) {
                $count++;
                continue;
            }

            $values = [
                'title' => $title,
                'description' => $commodities !== '' ? $commodities : null,
                'source_site_name' => self::SOURCE_SITE_NAME,
                'source_url' => $sourceUrl,
                'location' => $location,
                'published_at' => $this->parseDate($issueDateRaw),
                'date_publish_at' => $this->parseDate($issueDateRaw),
                'date_issue_at' => $this->parseDate($issueDateRaw),
                'date_closing_at' => $this->parseDate($closeDateRaw),
                'solicitation_number' => $opportunityId !== '' ? $opportunityId : null,
                'solicitation_type' => $type !== '' ? $type : null,
                'purchasing_group' => $orgIssuedBy !== '' ? $orgIssuedBy : null,
                'buyer_name' => $orgIssuedBy !== '' ? $orgIssuedBy : null,
                'source_status' => $status !== '' ? $status : null,
                'source_timezone' => 'America/Vancouver',
                'is_manual_entry' => false,
                'is_featured' => false,
                'source_raw' => [
                    'status' => $status,
                    'opportunity_id' => $opportunityId,
                    'commodities' => $commodities,
                    'type' => $type,
                    'issue_date' => $issueDateRaw,
                    'close_date' => $closeDateRaw,
                    'last_updated' => $lastUpdatedRaw,
                    'issued_by' => $orgIssuedBy,
                    'issued_for' => $orgIssuedFor,
                ],
            ];

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

        try {
            return Carbon::createFromFormat('Y-m-d g:i:s A', $trimmed, 'America/Vancouver');
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function fetchPlaywrightPages(int $maxPages): array
    {
        $scriptPath = base_path('scripts/bcbid-snapshot.mjs');
        $outputDir = storage_path('app/bc-bid-playwright');
        $manifestPath = $outputDir.DIRECTORY_SEPARATOR.'manifest.json';

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException('Playwright script not found at '.$scriptPath);
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $process = new Process(['node', $scriptPath]);
        $process->setTimeout(360);
        $process->setEnv([
            'BCBID_OUTPUT_DIR' => $outputDir,
            'BCBID_MANIFEST' => $manifestPath,
            'BCBID_PAGES' => (string) $maxPages,
            'BCBID_HEADLESS' => env('BCBID_HEADLESS', 'true'),
            'BCBID_TIMEOUT_MS' => env('BCBID_TIMEOUT_MS', '60000'),
            'BCBID_MANUAL' => env('BCBID_MANUAL', 'false'),
            'BCBID_USER_DATA_DIR' => env('BCBID_USER_DATA_DIR', storage_path('app/bcbid-profile')),
            'BCBID_USER_AGENT' => env('BC_BID_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36'),
            'BCBID_LOCALE' => env('BCBID_LOCALE', 'en-CA'),
            'BCBID_TIMEZONE' => env('BCBID_TIMEZONE', 'America/Vancouver'),
            'BCBID_STEALTH' => env('BCBID_STEALTH', 'true'),
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Playwright fetch failed: '.$process->getErrorOutput());
        }

        if (!file_exists($manifestPath)) {
            throw new \RuntimeException('Playwright did not produce a manifest.');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['pages'])) {
            throw new \RuntimeException('Playwright manifest is invalid.');
        }

        $pages = [];
        foreach ($manifest['pages'] as $pagePath) {
            if (file_exists($pagePath)) {
                $pages[] = file_get_contents($pagePath);
            }
        }

        return $pages;
    }

    private function cleanText(string $value): string
    {
        $clean = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        $clean = preg_replace('/\s+/', ' ', $clean);

        return trim($clean);
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

    private function absoluteUrl(string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return rtrim(self::BASE_HOST, '/') . '/' . ltrim($href, '/');
    }

    private function getScraperSettings(): array
    {
        $row = DB::table('scraper_settings')
            ->where('source_site_key', self::SOURCE_SITE_KEY)
            ->first();

        if (!$row || empty($row->settings)) {
            return [];
        }

        if (is_string($row->settings)) {
            $decoded = json_decode($row->settings, true);
            return is_array($decoded) ? $decoded : [];
        }

        return (array) $row->settings;
    }
}
