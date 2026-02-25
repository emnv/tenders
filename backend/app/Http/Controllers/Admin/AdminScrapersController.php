<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScraperSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class AdminScrapersController extends Controller
{
    private const SCRAPERS = [
        [
            'key' => 'barrie-bids-tenders',
            'name' => 'Barrie Bids & Tenders',
            'command' => 'scrape:barrie-tenders',
            'source_url' => 'https://barrie.bidsandtenders.ca/Module/Tenders/en/Tender/Search/a27a6121-d413-479f-be32-ec3d87c828b7',
            'params' => ['limit'],
        ],
        [
            'key' => 'windsor-bids-tenders',
            'name' => 'Windsor Bids & Tenders',
            'command' => 'scrape:windsor-bids',
            'source_url' => 'https://opendata.citywindsor.ca/Tools/BidsAndTenders',
            'params' => [],
        ],
        [
            'key' => 'toronto-bids-portal',
            'name' => 'Toronto Bids Portal',
            'command' => 'scrape:toronto-bids',
            'source_url' => 'https://www.toronto.ca/business-economy/doing-business-with-the-city/searching-bidding-on-city-contracts/toronto-bids-portal/',
            'params' => ['limit'],
        ],
        [
            'key' => 'merx-ottawa',
            'name' => 'MERX City of Ottawa',
            'command' => 'scrape:ottawa-merx',
            'source_url' => 'https://www.merx.com/cityofottawa/solicitations/open-bids',
            'params' => ['max_pages'],
        ],
        [
            'key' => 'pei-tenders',
            'name' => 'Prince Edward Island Tenders',
            'command' => 'scrape:pei-tenders',
            'source_url' => 'https://www.princeedwardisland.ca/en/feature/search-for-tenders-and-procurement-opportunities/',
            'params' => ['years'],
        ],
        [
            'key' => 'nova-scotia-procurement',
            'name' => 'Nova Scotia Procurement Portal',
            'command' => 'scrape:nova-scotia',
            'source_url' => 'https://procurement-portal.novascotia.ca/tenders',
            'params' => ['pages'],
        ],
        [
            'key' => 'infrastructure-ontario-projects',
            'name' => 'Infrastructure Ontario Projects',
            'command' => 'scrape:infrastructure-ontario',
            'source_url' => 'https://www.infrastructureontario.ca/en/what-we-do/projectssearch/?cpage=1&facets=projectstage%3Ainprocurement',
            'params' => ['pages'],
        ],
        [
            'key' => 'sasktenders',
            'name' => 'Saskatchewan Tenders',
            'command' => 'scrape:sasktenders',
            'source_url' => 'https://sasktenders.ca/content/public/Search.aspx',
            'params' => ['pages'],
        ],
        [
            'key' => 'alberta-purchasing',
            'name' => 'Alberta Purchasing',
            'command' => 'scrape:alberta-purchasing',
            'source_url' => 'https://purchasing.alberta.ca/search',
            'params' => ['limit', 'pages'],
        ],
        [
            'key' => 'kenora-tenders',
            'name' => 'Kenora Tenders',
            'command' => 'scrape:kenora-tenders',
            'source_url' => 'https://kenora.bidsandtenders.ca/',
            'params' => [],
        ],
        [
            'key' => 'bc-bid',
            'name' => 'British Columbia Bid',
            'command' => 'scrape:bc-bid',
            'source_url' => 'https://bcbid.gov.bc.ca/page.aspx/en/rfp/request_browse_public',
            'params' => ['expected_count', 'session_id', 'csrf_token', 'cookie_header'],
            'option_map' => [
                'expected_count' => 'expected-count',
                'session_id' => 'session',
                'csrf_token' => 'csrf',
                'cookie_header' => 'cookie-header',
            ],
        ],
        [
            'key' => 'ontario-highway-programs',
            'name' => 'Ontario Highway Programs',
            'command' => 'scrape:ontario-highway-programs',
            'source_url' => 'https://www.ontario.ca/page/ontarios-highway-programs',
            'params' => [],
        ],
    ];

    public function index(): JsonResponse
    {
        $settings = ScraperSetting::query()->get()->keyBy('source_site_key');
        $recentRuns = DB::table('scrape_runs')
            ->select('source_site_key', 'status', 'started_at', 'finished_at', 'items_found', 'items_upserted')
            ->orderByDesc('started_at')
            ->get()
            ->groupBy('source_site_key');

        $data = array_map(function (array $scraper) use ($settings, $recentRuns) {
            $setting = $settings->get($scraper['key']);
            $latestRun = $recentRuns->get($scraper['key'])?->first();

            return [
                'key' => $scraper['key'],
                'name' => $scraper['name'],
                'command' => $scraper['command'],
                'source_url' => $scraper['source_url'] ?? null,
                'params' => $scraper['params'],
                'is_enabled' => $setting?->is_enabled ?? true,
                'settings' => $setting?->settings ?? new \stdClass(),
                'latest_run' => $latestRun,
            ];
        }, self::SCRAPERS);

        return response()->json([
            'data' => $data,
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
        ]);

        $scraper = collect(self::SCRAPERS)->firstWhere('key', $key);
        if (!$scraper) {
            return response()->json([
                'message' => 'Unknown scraper key.',
            ], 404);
        }

        $setting = ScraperSetting::query()->firstOrNew(['source_site_key' => $key]);

        if (array_key_exists('is_enabled', $validated)) {
            $setting->is_enabled = (bool) $validated['is_enabled'];
        }

        if (array_key_exists('settings', $validated)) {
            $setting->settings = $validated['settings'];
        }

        $setting->save();

        return response()->json([
            'data' => $setting,
        ]);
    }

    public function run(string $key): JsonResponse
    {
        $scraper = collect(self::SCRAPERS)->firstWhere('key', $key);
        if (!$scraper) {
            return response()->json([
                'message' => 'Unknown scraper key.',
            ], 404);
        }

        $setting = ScraperSetting::query()->where('source_site_key', $key)->first();
        if ($setting && !$setting->is_enabled) {
            return response()->json([
                'message' => 'Scraper is disabled. Enable it first.',
            ], 422);
        }

        // Build artisan arguments from stored settings
        $arguments = [];
        $storedSettings = $setting?->settings ?? [];
        foreach ($scraper['params'] as $param) {
            $value = $storedSettings[$param] ?? null;
            if ($value !== null && $value !== '') {
                $optionName = $scraper['option_map'][$param] ?? str_replace('_', '-', $param);
                $arguments['--' . $optionName] = $value;
            }
        }

        try {
            $exitCode = Artisan::call($scraper['command'], $arguments);
            $output = Artisan::output();

            // Fetch the latest run that was just created
            $latestRun = DB::table('scrape_runs')
                ->where('source_site_key', $key)
                ->orderByDesc('started_at')
                ->first();

            return response()->json([
                'exit_code' => $exitCode,
                'output' => trim($output),
                'latest_run' => $latestRun,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Scraper failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
