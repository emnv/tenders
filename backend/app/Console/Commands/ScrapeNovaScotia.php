<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Nova Scotia Procurement Portal Scraper
 * 
 * NOTE: This endpoint has WAF (Web Application Firewall) protection that may block
 * server-side requests. If scraping fails with "Request Rejected" errors, you may need:
 * 1. Valid session cookies from a browser
 * 2. A headless browser solution (Puppeteer/Playwright)
 * 3. Contact the portal for API access
 */
class ScrapeNovaScotia extends Command
{
    protected $signature = 'scrape:nova-scotia {--pages=50 : Maximum pages to fetch}';

    protected $description = 'Ingest tenders from Nova Scotia Procurement Portal (JSON endpoint).';

    private const SOURCE_SITE_KEY = 'nova-scotia-procurement';
    private const SOURCE_SITE_NAME = 'Nova Scotia Procurement Portal';
    private const LOCATION_DEFAULT = 'Nova Scotia';
    private const BASE_URL = 'https://procurement-portal.novascotia.ca/procurementui/tenders';
    private const FRONTEND_BASE_URL = 'https://procurement-portal.novascotia.ca/tenders';
    private const RECORDS_PER_PAGE = 100;
    
    // Long-lived GUEST token (expires 2087) - obtained from browser inspection
    // If this stops working, visit the site in a browser and copy the new Authorization header
    private const GUEST_TOKEN = 'eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJHVUVTVCIsImV4cCI6MzcxMzM5NzkzNSwiaWF0IjoxNzY5Mzk3OTM1fQ.4-oKWPePtxH1zaJ_eYfqnXR0NGYMF0OjokR7c-CdICyAAH42JmR_rIwTcXRO3dZ958kVsVxMeNwEzN5J9prJYQ';

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
            $rows = [];
            $page = 1;
            $totalPages = 1;

            while ($page <= $maxPages && $page <= $totalPages) {
                $this->info("Fetching page {$page}...");
                $payload = $this->fetchPage($page);

                // Check for WAF block or empty response
                if (empty($payload) || isset($payload['error'])) {
                    $this->warn('API returned error or empty response. Site may have WAF protection.');
                    break;
                }

                $pagination = $payload['paginationData'] ?? [];
                $totalRecords = $pagination['totalRecords'] ?? 0;
                
                if ($totalRecords === 0) {
                    $this->warn('No records returned. API may be blocked or unavailable.');
                    break;
                }
                
                $totalPages = (int) ceil($totalRecords / self::RECORDS_PER_PAGE);
                $this->info("Total records: {$totalRecords}, Total pages: {$totalPages}");

                $tenders = $payload['tenderDataList'] ?? [];
                if (empty($tenders)) {
                    break;
                }

                foreach ($tenders as $tender) {
                    $rows[] = $this->mapTender($tender);
                }

                $page++;

                // Rate limiting - be respectful
                usleep(500000); // 500ms delay between requests
            }

            $itemsFound = count($rows);

            foreach ($rows as $row) {
                $itemsUpserted += $this->upsertProject($row);
            }

            DB::table('scrape_runs')->where('id', $runId)->update([
                'status' => $itemsFound > 0 ? 'success' : 'warning',
                'finished_at' => now(),
                'items_found' => $itemsFound,
                'items_upserted' => $itemsUpserted,
                'message' => $itemsFound === 0 ? 'No items found - API may be protected by WAF' : null,
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Nova Scotia tenders.");

        return Command::SUCCESS;
    }

    private function fetchPage(int $page): array
    {
        // Build URL exactly as browser does - with empty params
        $url = self::BASE_URL . '?' . http_build_query([
            'page' => $page,
            'numberOfRecords' => self::RECORDS_PER_PAGE,
            'sortType' => 'POSTED_DATE_DESC',
            'keyword' => '',
            'myOrganization' => '',
            'mine' => '',
            'watchlist' => '',
        ]);

        $response = Http::timeout(30)
            ->retry(3, 1000)
            ->withHeaders([
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Authorization' => 'Bearer ' . self::GUEST_TOKEN,
                'Content-Length' => '0',
                'Origin' => 'https://procurement-portal.novascotia.ca',
                'Referer' => 'https://procurement-portal.novascotia.ca/tenders',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
                'sec-ch-ua' => '"Chromium";v="141", "Not?A_Brand";v="8"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-origin',
            ])
            ->withOptions([
                'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
            ])
            ->send('POST', $url, ['body' => '']);

        if (!$response->successful()) {
            // Check for WAF block (returns HTML with "Request Rejected")
            $body = $response->body();
            if (str_contains($body, 'Request Rejected')) {
                $this->error('Request blocked by WAF. Server-side scraping may not be possible.');
                return ['error' => 'WAF_BLOCKED'];
            }
            throw new \RuntimeException('Nova Scotia request failed with status ' . $response->status());
        }

        $body = $response->body();
        
        // Check for WAF block even on 200 response
        if (str_contains($body, 'Request Rejected') || str_contains($body, '<html>')) {
            $this->error('Request blocked by WAF (HTML response received).');
            return ['error' => 'WAF_BLOCKED'];
        }

        return $response->json() ?? [];
    }

    /**
     * Map tender data from API response to project structure.
     */
    private function mapTender(array $tender): array
    {
        $closingDate = null;
        if (!empty($tender['closingDate'])) {
            try {
                // Format: "2026-02-13 04:00:00" (UTC)
                $closingDate = Carbon::parse($tender['closingDate'])->toDateTimeString();
            } catch (Throwable $e) {
                // Ignore parsing errors
            }
        }

        $postDate = null;
        if (!empty($tender['postDate'])) {
            try {
                // Format: "2026-01-23"
                $postDate = Carbon::parse($tender['postDate'])->toDateTimeString();
            } catch (Throwable $e) {
                // Ignore parsing errors
            }
        }

        // Build source URL - link to tender details
        $sourceUrl = self::FRONTEND_BASE_URL;
        if (!empty($tender['id'])) {
            $sourceUrl = self::FRONTEND_BASE_URL . '/' . $tender['id'];
        }

        // Build location from procurement entity
        $location = self::LOCATION_DEFAULT;
        $entity = $tender['procurementEntity'] ?? '';
        if (!empty($entity)) {
            $location = $entity . ', ' . self::LOCATION_DEFAULT;
        }

        return [
            'source_external_id' => (string) ($tender['id'] ?? ''),
            'solicitation_number' => $tender['tenderId'] ?? null,
            'solicitation_type' => $tender['solicitationType'] ?? null,
            'title' => $tender['title'] ?? 'Untitled',
            'description' => $tender['description'] ?? null,
            'location' => $location,
            'buyer_name' => $tender['procurementEntity'] ?? null,
            'purchasing_group' => $tender['endUserEntity'] ?? null,
            'source_status' => $tender['tenderStatus'] ?? null,
            'date_closing_at' => $closingDate,
            'date_publish_at' => $postDate,
            'source_url' => $sourceUrl,
            'source_raw' => $tender,
        ];
    }

    private function upsertProject(array $row): int
    {
        $externalId = $row['source_external_id'] ?? null;

        if (!$externalId) {
            return 0;
        }

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => (string) $externalId,
        ];

        $status = $row['source_status'] ?? null;

        // Only create new projects if they have an open status.
        // Always update existing projects so status changes are captured.
        $exists = Project::where($attributes)->exists();

        if (!$exists && !$this->isOpenStatus($status)) {
            return 0;
        }

        $values = [
            'title' => $row['title'] ?? 'Untitled',
            'description' => $row['description'] ?? null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $row['source_url'] ?? self::FRONTEND_BASE_URL,
            'location' => $row['location'] ?? self::LOCATION_DEFAULT,
            'published_at' => $row['date_publish_at'] ?? null,
            'date_publish_at' => $row['date_publish_at'] ?? null,
            'date_closing_at' => $row['date_closing_at'] ?? null,
            'solicitation_number' => $row['solicitation_number'] ?? null,
            'solicitation_type' => $row['solicitation_type'] ?? null,
            'buyer_name' => $row['buyer_name'] ?? null,
            'purchasing_group' => $row['purchasing_group'] ?? null,
            'source_status' => $status,
            'source_timezone' => 'America/Halifax',
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $row['source_raw'] ?? null,
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    /**
     * Determine whether a status string represents an open/active tender.
     */
    private function isOpenStatus(?string $status): bool
    {
        if ($status === null || trim($status) === '') {
            return true; // Assume open when no status is available
        }

        $openStatuses = ['open', 'active', 'published'];

        return in_array(strtolower(trim($status)), $openStatuses, true);
    }
}
