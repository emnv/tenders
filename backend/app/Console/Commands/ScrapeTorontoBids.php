<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeTorontoBids extends Command
{
    protected $signature = 'scrape:toronto-bids {--limit=50}';

    protected $description = 'Ingest open solicitations from Toronto Bids Portal (JSON endpoint).';

    private const SOURCE_SITE_KEY = 'toronto-bids-portal';
    private const SOURCE_SITE_NAME = 'Toronto Bids Portal';
    private const BASE_URL = 'https://secure.toronto.ca/c3api_data/v2/DataAccess.svc/pmmd_solicitations/feis_solicitation_published';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $skip = 0;
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
            $total = null;

            do {
                $response = Http::timeout(30)
                    ->retry(3, 500)
                    ->withHeaders([
                        'Accept' => 'application/json',
                    ])
                    ->withOptions([
                        'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
                    ])
                    ->get(self::BASE_URL, [
                        '$format' => 'application/json;odata.metadata=none',
                        '$count' => 'true',
                        '$skip' => $skip,
                        '$top' => $limit,
                        '$filter' => "Ready_For_Posting eq 'Yes' and Status eq 'Open'",
                        '$orderby' => 'Closing_Date desc,Issue_Date desc',
                    ]);

                if (!$response->successful()) {
                    throw new \RuntimeException('Toronto API request failed with status '.$response->status());
                }

                $payload = $response->json();
                $data = $payload['value'] ?? [];
                $total = $total ?? (int) ($payload['@odata.count'] ?? 0);

                $itemsFound += count($data);

                foreach ($data as $item) {
                    $itemsUpserted += $this->upsertProject($item);
                }

                $skip += $limit;
            } while ($total === 0 ? count($data) > 0 : $skip < $total);

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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Toronto solicitations.");

        return Command::SUCCESS;
    }

    private function upsertProject(array $item): int
    {
        $externalId = $item['id'] ?? $item['Parent_Id'] ?? null;

        if (!$externalId) {
            return 0;
        }

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => (string) $externalId,
        ];

        $publishAt = $this->parseDate($item['Publish_Date_Formatted'] ?? $item['Publish_Date'] ?? null);
        $issueAt = $this->parseDate($item['Issue_Date_Formatted'] ?? $item['Issue_Date'] ?? null);
        $closingAt = $this->parseDate($item['Closing_Date_Formatted'] ?? $item['Closing_Date'] ?? null);

        $aribaLink = $this->nullIfPlaceholder($item['Ariba_Discovery_Posting_Link'] ?? null);

        $values = [
            'title' => $item['Posting_Title'] ?? 'Untitled solicitation',
            'description' => $item['Solicitation_Document_Description'] ?? null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $aribaLink ?? 'https://www.toronto.ca/business-economy/doing-business-with-the-city/searching-bidding-on-city-contracts/toronto-bids-portal/',
            'location' => $item['Buyer_Location'] ?? 'Toronto, ON',
            'published_at' => $publishAt,
            'date_publish_at' => $publishAt,
            'date_issue_at' => $issueAt,
            'date_closing_at' => $closingAt,
            'solicitation_number' => $item['Solicitation_Document_Number'] ?? null,
            'solicitation_type' => $item['Solicitation_Document_Type'] ?? null,
            'solicitation_form_type' => $item['Solicitation_Form_Type'] ?? null,
            'purchasing_group' => $item['Purchasing_Group'] ?? null,
            'high_level_category' => $item['High_Level_Category'] ?? null,
            'client_divisions' => $item['Client_Division'] ?? null,
            'buyer_name' => $item['Buyer_Name'] ?? null,
            'buyer_email' => $item['Buyer_Email'] ?? null,
            'buyer_phone' => $item['Buyer_Phone_Number'] ?? null,
            'buyer_location' => $item['Buyer_Location'] ?? null,
            'ariba_discovery_url' => $aribaLink,
            'wards' => $item['Wards'] ?? null,
            'pre_bid_meeting' => $item['Pre_Bid_Meeting'] ?? null,
            'contract_duration' => $item['Contract_Duration'] ?? null,
            'specific_conditions' => $item['Specific_Conditions'] ?? null,
            'source_status' => $item['Status'] ?? null,
            'source_scope' => $item['High_Level_Category'] ?? null,
            'source_timezone' => 'America/Toronto',
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $item,
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value, 'America/Toronto');
    }

    private function nullIfPlaceholder(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || str_contains(strtolower($trimmed), 'not been posted') || $trimmed === 'TBD') {
            return null;
        }

        return $trimmed;
    }
}
