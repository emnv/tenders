<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScrapeNunavutTenders extends Command
{
    protected $signature = 'scrape:nunavut-tenders';

    protected $description = 'Ingest open tenders from Nunavut Tenders (HTML table parsing).';

    private const SOURCE_SITE_KEY = 'nunavut-tenders';
    private const SOURCE_SITE_NAME = 'Nunavut Tenders';
    private const LOCATION_DEFAULT = 'Nunavut';
    private const BASE_URL = 'https://nunavuttenders.ca';

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
            $html = $this->fetchPage();
            $rows = $this->parseRows($html);

            $itemsFound = count($rows);

            foreach ($rows as $row) {
                $itemsUpserted += $this->upsertProject($row);
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

        $this->info("Ingested {$itemsUpserted} of {$itemsFound} Nunavut tenders.");

        return Command::SUCCESS;
    }

    private function fetchPage(): string
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
            ->get(self::BASE_URL);

        if (!$response->successful()) {
            throw new \RuntimeException('Nunavut Tenders request failed with status ' . $response->status());
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

        // The open tenders table has id="GridView1"
        $table = $xpath->query('//table[@id="GridView1"]')->item(0);

        if (!$table) {
            return [];
        }

        // Skip the header row (first tr with th elements)
        $rows = $xpath->query('.//tr[td]', $table);
        $results = [];

        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);

            // Expect 8 columns: Ref#, Description, FOB/Location, Issued Date,
            // Contact Person, Phone/Email, Closing Date/Time, Electronic Bid Submission
            if ($cells->length < 7) {
                continue;
            }

            // Column 0: Ref# — contains a link with span text
            $refLink = $xpath->query('.//a[@href]', $cells->item(0))->item(0);
            $refNumber = $this->textContent($refLink);
            $refHref = $refLink instanceof \DOMElement ? $refLink->getAttribute('href') : null;

            // Column 1: Description (title)
            $title = $this->textContent($cells->item(1));
            if (!$title) {
                continue;
            }

            // Column 2: FOB Point Or Location
            $location = $this->textContent($cells->item(2));

            // Column 3: Issued Date (YYYY-MM-DD)
            $issuedDateRaw = $this->textContent($cells->item(3));

            // Column 4: Contact Person
            $contactPerson = $this->textContent($cells->item(4));

            // Column 5: Phone Number and/or Email
            $contactCell = $cells->item(5);
            $contactPhone = null;
            $contactEmail = null;
            if ($contactCell) {
                $emailLink = $xpath->query('.//a[starts-with(@href, "mailto:")]', $contactCell)->item(0);
                if ($emailLink instanceof \DOMElement) {
                    $contactEmail = $this->textContent($emailLink);
                }
                // Phone is the text node before the email link
                $fullContactText = $this->textContent($contactCell);
                if ($fullContactText && $contactEmail) {
                    $contactPhone = trim(str_replace($contactEmail, '', $fullContactText));
                } elseif ($fullContactText) {
                    $contactPhone = $fullContactText;
                }
            }

            // Column 6: Closing Date And Time (e.g. "2026-05-04 15:00 ET")
            $closingDateRaw = $this->textContent($cells->item(6));

            $results[] = [
                'ref_number' => $refNumber,
                'ref_href' => $refHref,
                'title' => $title,
                'location' => $location,
                'issued_date_raw' => $issuedDateRaw,
                'closing_date_raw' => $closingDateRaw,
                'contact_person' => $contactPerson,
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail,
            ];
        }

        return $results;
    }

    private function upsertProject(array $row): int
    {
        // Use ref number as external ID; fall back to MD5 of title + location
        $externalId = $row['ref_number']
            ?? md5(($row['title'] ?? '') . '|' . ($row['location'] ?? ''));

        $attributes = [
            'source_site_key' => self::SOURCE_SITE_KEY,
            'source_external_id' => (string) $externalId,
        ];

        $issuedAt = $this->parseDate($row['issued_date_raw'] ?? null);
        $closingAt = $this->parseClosingDate($row['closing_date_raw'] ?? null);

        $values = [
            'title' => $row['title'],
            'description' => null,
            'source_site_name' => self::SOURCE_SITE_NAME,
            'source_url' => $row['ref_href'] ?? self::BASE_URL,
            'location' => $row['location'] ?? self::LOCATION_DEFAULT,
            'published_at' => $issuedAt,
            'date_issue_at' => $issuedAt,
            'date_closing_at' => $closingAt,
            'solicitation_number' => $row['ref_number'],
            'buyer_name' => $row['contact_person'],
            'buyer_email' => $row['contact_email'],
            'buyer_phone' => $row['contact_phone'],
            'source_status' => 'Open',
            'source_timezone' => 'America/Toronto',
            'is_manual_entry' => false,
            'is_featured' => false,
            'source_raw' => $row,
        ];

        $project = Project::updateOrCreate($attributes, $values);

        return $project->wasRecentlyCreated || $project->wasChanged() ? 1 : 0;
    }

    /**
     * Parse issued date in YYYY-MM-DD format.
     */
    private function parseDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);

        try {
            return Carbon::createFromFormat('Y-m-d', $value, 'America/Toronto')?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse closing date like "2026-05-04 15:00 ET".
     */
    private function parseClosingDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);

        // Strip timezone abbreviation (ET, CT, etc.) — dates are Eastern Time
        $value = preg_replace('/\s+(ET|CT|MT|PT|EST|CST|MST|PST|EDT|CDT|MDT|PDT)\s*$/i', '', $value);

        try {
            return Carbon::createFromFormat('Y-m-d H:i', $value, 'America/Toronto');
        } catch (Throwable) {
            return null;
        }
    }

    private function textContent(?\DOMNode $node): ?string
    {
        if (!$node) {
            return null;
        }

        $text = trim($node->textContent ?? '');

        return $text !== '' ? $text : null;
    }
}
