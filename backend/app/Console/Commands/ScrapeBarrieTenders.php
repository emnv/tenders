<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeBarrieTenders extends Command
{
    protected $signature = 'scrape:barrie-tenders {--limit=25}';

    protected $description = 'Ingest open tenders from City of Barrie (Bids & Tenders) into the projects table.';

    private const SOURCE_SITE_KEY = 'barrie-bids-tenders';
    private const SOURCE_SITE_NAME = 'Barrie Bids & Tenders';
    private const LOCATION = 'Barrie, ON';
    private const BASE_URL = 'https://barrie.bidsandtenders.ca/Module/Tenders/en/Tender/Search/a27a6121-d413-479f-be32-ec3d87c828b7';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $start = 0;
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
            $cookieJar = new CookieJar();
            $client = Http::timeout(30)
                ->retry(3, 500)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => 'https://barrie.bidsandtenders.ca/Module/Tenders/en',
                    'Origin' => 'https://barrie.bidsandtenders.ca',
                    'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
                ])
                ->withOptions([
                    'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
                    'cookies' => $cookieJar,
                ]);

            $warmup = $client->get('https://barrie.bidsandtenders.ca/Module/Tenders/en');

            if (!$warmup->successful()) {
                throw new \RuntimeException('Barrie warm-up request failed with status '.$warmup->status());
            }

            $warmupBody = $warmup->body();
            $verificationToken = null;

            if (preg_match('/name="__RequestVerificationToken"[^>]*value="([^"]+)"/i', $warmupBody, $matches) === 1) {
                $verificationToken = $matches[1];
            }

            if (!$verificationToken) {
                foreach ($cookieJar->toArray() as $cookie) {
                    if (in_array($cookie['Name'], ['XSRF-TOKEN', '__RequestVerificationToken'], true)) {
                        $verificationToken = urldecode($cookie['Value']);
                        break;
                    }
                }
            }

            if ($verificationToken) {
                $client = $client->withHeaders([
                    'RequestVerificationToken' => $verificationToken,
                    'X-XSRF-TOKEN' => $verificationToken,
                ]);
            }

            do {
                $formData = [
                    'status' => 'Open',
                    'limit' => $limit,
                    'start' => $start,
                    'dir' => 'ASC',
                    'from' => '',
                    'to' => '',
                    'sort' => 'DateClosing ASC,Id',
                ];

                if ($verificationToken) {
                    $formData['__RequestVerificationToken'] = $verificationToken;
                }

                $response = $client
                    ->asForm()
                    ->post(self::BASE_URL, $formData);

                if (!$response->successful()) {
                    throw new \RuntimeException('Barrie API request failed with status '.$response->status());
                }

                $contentType = $response->header('Content-Type');

                if (!$contentType || !str_contains($contentType, 'application/json')) {
                    throw new \RuntimeException('Barrie API returned non-JSON response.');
                }

                $payload = $response->json();
                $data = $payload['data'] ?? [];
                $total = (int) ($payload['total'] ?? 0);

                $itemsFound += count($data);

                foreach ($data as $item) {
                    $itemsUpserted += $this->upsertProject($item);
                }

                $start += $limit;
            } while ($start < $total);

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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Barrie tenders.");

        return Command::SUCCESS;
    }

    private function upsertProject(array $item): int
    {
        $externalId = $item['Id'] ?? null;
        $title = $item['Title'] ?? null;

        if (!$externalId || !$title) {
            return 0;
        }

        $dateAvailable = $this->parseBidsDate($item['DateAvailable'] ?? null);
        $dateClosing = $this->parseBidsDate($item['DateClosing'] ?? null);

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => $externalId,
        ];

        $values = [
            'title' => $title,
            'description' => $item['Description'] ?? null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => sprintf(
                'https://barrie.bidsandtenders.ca/Module/Tenders/en/Tender/Detail/%s',
                $externalId
            ),
            'location' => self::LOCATION,
            'published_at' => $dateAvailable,
            'date_available_at' => $dateAvailable,
            'date_closing_at' => $dateClosing,
            'source_status' => $item['Status'] ?? null,
            'source_scope' => $item['Scope'] ?? null,
            'source_timezone' => $item['TimeZoneLabel'] ?? null,
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $item,
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    private function parseBidsDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        if (preg_match('/\/Date\((\d+)\)\//', $value, $matches) !== 1) {
            return null;
        }

        $timestampMs = (int) $matches[1];

        return Carbon::createFromTimestampMs($timestampMs, 'UTC')
            ->setTimezone('America/Toronto');
    }

}
