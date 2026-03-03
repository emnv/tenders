<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeQuebecSeao extends Command
{
    protected $signature = 'scrape:quebec-seao {--url= : Custom SEAO search URL}';

    protected $description = 'Ingest construction notices from Quebec SEAO search results (public API).';

    private const SOURCE_SITE_KEY = 'quebec-seao';
    private const SOURCE_SITE_NAME = 'Quebec SEAO Construction Opportunities';
    private const BASE_HOST = 'https://seao.gouv.qc.ca';
    private const API_BASE = 'https://api.seao.gouv.qc.ca/prod/api';
    private const DEFAULT_URL = 'https://seao.gouv.qc.ca/avis-resultat-recherche?statIds=6&tpIds=2%2C3%2C5%2C6%2C7%2C8%2C10%2C14%2C15%2C17%2C18%2C19&catIds=52&prov=AvisDuJour&addendaPublieDerniereVisite=false';

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
            $url = trim((string) ($this->option('url') ?: self::DEFAULT_URL));
            $apiUrl = $this->buildApiSearchUrl($url);

            $response = Http::timeout(30)
                ->retry(3, 600)
                ->withHeaders([
                    'Accept' => 'application/json, text/plain, */*',
                    'Origin' => self::BASE_HOST,
                    'Referer' => self::BASE_HOST.'/',
                    'User-Agent' => 'Mozilla/5.0 (compatible; OCN Tenders Bot/1.0)',
                ])
                ->withOptions([
                    'verify' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
                ])
                ->get($apiUrl);

            if (!$response->successful()) {
                throw new \RuntimeException('SEAO request failed with status '.$response->status());
            }

            $rows = $this->parseRows($response->json());
            $itemsFound = count($rows);

            foreach ($rows as $row) {
                $itemsUpserted += $this->upsertProject($row, $url);
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Quebec SEAO notices.");

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseRows(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $apiData = $payload['apiData'] ?? null;
        $results = is_array($apiData) ? ($apiData['results'] ?? null) : null;

        if (!is_array($results)) {
            return [];
        }

        $result = [];

        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!$this->isConstructionItem($item)) {
                continue;
            }

            $id = isset($item['id']) ? (string) $item['id'] : null;
            $uuid = isset($item['uuid']) ? (string) $item['uuid'] : null;
            $title = $this->cleanText($item['titre'] ?? null);
            $solicitation = $this->cleanText($item['numero'] ?? null);
            $organization = $this->cleanText($item['nomDonneurOuvrage'] ?? null);
            $publishRaw = $this->cleanText($item['datePublicationUtc'] ?? null);
            $closingRaw = $this->cleanText($item['dateFermetureUtc'] ?? null);

            $externalId = $id ?: ($uuid ?: ($solicitation ?: sha1(($title ?? '').'|'.($publishRaw ?? '').'|'.($organization ?? ''))));

            if (!$title && !$solicitation) {
                continue;
            }

            $result[] = [
                'source_external_id' => $externalId,
                'title' => $title ?: ($solicitation ?: 'Avis d’appel d’offres'),
                'solicitation_number' => $solicitation,
                'source_url' => $this->buildNoticeUrl($id),
                'source_status' => $this->mapStatus($item['statutAvisId'] ?? null),
                'high_level_category' => 'Construction',
                'buyer_name' => $organization,
                'location' => 'Québec',
                'date_publish_raw' => $publishRaw,
                'date_closing_raw' => $closingRaw,
                'type_line' => isset($item['typeAvisId']) ? 'Type '.$item['typeAvisId'] : null,
                'raw_id' => $id,
                'raw_uuid' => $uuid,
            ];
        }

        return $result;
    }

    private function upsertProject(array $row, string $fallbackUrl): int
    {
        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => (string) $row['source_external_id'],
        ];

        $publishAt = $this->parseDateTime($row['date_publish_raw'] ?? null);
        $closingAt = $this->parseDateTime($row['date_closing_raw'] ?? null);

        $values = [
            'title' => $row['title'] ?? 'Avis d’appel d’offres',
            'description' => null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $row['source_url'] ?? $fallbackUrl,
            'location' => $row['location'] ?? 'Québec',
            'published_at' => $publishAt,
            'date_publish_at' => $publishAt,
            'date_closing_at' => $closingAt,
            'solicitation_number' => $row['solicitation_number'] ?? null,
            'high_level_category' => 'Construction',
            'buyer_name' => $row['buyer_name'] ?? null,
            'source_status' => $row['source_status'] ?? 'Publié',
            'source_scope' => $row['type_line'] ?? null,
            'source_timezone' => 'America/Toronto',
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $row,
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    private function parseDateTime(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        $formats = [
            'Y-m-d\TH:i:s.u\Z',
            'Y-m-d\TH:i:s\Z',
            'Y-m-d H:i',
            'Y-m-d',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $normalized, 'UTC');

                return $date->setTimezone('America/Toronto');
            } catch (Throwable $exception) {
                continue;
            }
        }

        return null;
    }

    private function isConstructionItem(array $item): bool
    {
        $categoryId = $item['categorieId'] ?? null;
        if ((int) $categoryId === 52) {
            return true;
        }

        $title = $this->cleanText($item['titre'] ?? null);

        return is_string($title) && preg_match('/construction|r[ée]fection|toiture|ventilation|ma[çc]onnerie|b[âa]timents?/iu', $title) === 1;
    }

    private function buildNoticeUrl(?string $id): ?string
    {
        if (!$id) {
            return null;
        }

        return self::BASE_HOST.'/avis-resultat-recherche/consulter?ItemId='.urlencode($id);
    }

    private function buildApiSearchUrl(string $inputUrl): string
    {
        $parsed = parse_url($inputUrl);
        $query = is_array($parsed) ? ($parsed['query'] ?? '') : '';

        if (str_contains($inputUrl, '/prod/api/recherche')) {
            return $inputUrl;
        }

        $base = rtrim(self::API_BASE, '/').'/recherche';

        return is_string($query) && $query !== '' ? $base.'?'.$query : $base;
    }

    private function mapStatus(mixed $statusId): string
    {
        $normalized = (int) $statusId;

        return match ($normalized) {
            6 => 'Publié',
            8 => 'Fermé',
            default => 'Publié',
        };
    }

    private function normalizeSecondaryId(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $trimmed = trim($value);
        $trimmed = ltrim($trimmed, '/');

        return $trimmed !== '' ? $trimmed : null;
    }

    private function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($text));

        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        return html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5);
    }
}
