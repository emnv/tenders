<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsController extends Controller
{
    public function index(): JsonResponse
    {
        $now = Carbon::now();
        $since24h = $now->copy()->subDay();
        $since7d = $now->copy()->subDays(7);

        $totalProjects = Project::query()->count();
        $featuredProjects = Project::query()->where('is_featured', true)->count();
        $sources = Project::query()
            ->whereNotNull('source_site_name')
            ->distinct('source_site_name')
            ->count('source_site_name');

        $projectsAdded7d = Project::query()
            ->where('created_at', '>=', $since7d)
            ->count();

        $closingSoon7d = Project::query()
            ->whereNotNull('date_closing_at')
            ->where('date_closing_at', '>=', $now)
            ->where('date_closing_at', '<=', $now->copy()->addDays(7))
            ->count();

        $recentRuns = DB::table('scrape_runs')
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        $lastRunAt = DB::table('scrape_runs')->max('started_at');

        $runs24h = DB::table('scrape_runs')
            ->where('started_at', '>=', $since24h)
            ->get();

        $successfulRuns24h = $runs24h->where('status', 'success')->count();
        $failedRuns24h = $runs24h->whereIn('status', ['error', 'failed'])->count();
        $totalRuns24h = $runs24h->count();
        $successRate24h = $totalRuns24h > 0
            ? round(($successfulRuns24h / $totalRuns24h) * 100, 1)
            : 0.0;

        $totalItemsFound24h = (int) $runs24h->sum('items_found');
        $totalItemsUpserted24h = (int) $runs24h->sum('items_upserted');
        $upsertRate24h = $totalItemsFound24h > 0
            ? round(($totalItemsUpserted24h / $totalItemsFound24h) * 100, 1)
            : 0.0;

        $projectsBySource = Project::query()
            ->selectRaw('source_site_name, COUNT(*) as total')
            ->whereNotNull('source_site_name')
            ->groupBy('source_site_name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'total_projects' => $totalProjects,
                'featured_projects' => $featuredProjects,
                'sources' => $sources,
                'projects_added_7d' => $projectsAdded7d,
                'closing_soon_7d' => $closingSoon7d,
                'last_run_at' => $lastRunAt,
                'successful_runs_24h' => $successfulRuns24h,
                'failed_runs_24h' => $failedRuns24h,
                'success_rate_24h' => $successRate24h,
                'total_items_found_24h' => $totalItemsFound24h,
                'total_items_upserted_24h' => $totalItemsUpserted24h,
                'upsert_rate_24h' => $upsertRate24h,
                'recent_runs' => $recentRuns,
                'projects_by_source' => $projectsBySource,
            ],
        ]);
    }
}
