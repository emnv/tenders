<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ProjectController extends Controller
{
    private function publicProjectsQuery(): Builder
    {
        $query = Project::query();

        if (!Schema::hasTable('scraper_settings')) {
            return $query;
        }

        return $query
            ->where(function (Builder $query): void {
                $query->whereNull('source_site_key')
                    ->orWhere('source_site_key', '')
                    ->orWhereNotExists(function ($subquery): void {
                        $subquery->selectRaw('1')
                            ->from('scraper_settings')
                            ->whereColumn('scraper_settings.source_site_key', 'projects.source_site_key')
                            ->where('scraper_settings.is_enabled', false);
                    });
            });
    }

    public function index(): JsonResponse
    {
        try {
            $projects = $this->publicProjectsQuery()
                ->where('is_featured', true)
                ->orderByDesc('published_at')
                ->limit(4)
                ->get();
        } catch (Throwable) {
            $projects = collect();
        }

        return response()->json([
            'data' => $projects,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:open,awarded,expired'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->publicProjectsQuery();

        if (!empty($validated['q'])) {
            $keyword = $validated['q'];
            $query->where(function ($builder) use ($keyword): void {
                $builder
                    ->where('title', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%")
                    ->orWhere('location', 'like', "%{$keyword}%");
            });
        }

        if (!empty($validated['source'])) {
            $source = trim($validated['source']);
            $normalizedSource = Str::lower($source);
            $sourceKey = Str::slug($source);

            $query->where(function (Builder $builder) use ($source, $normalizedSource, $sourceKey): void {
                $builder
                    ->whereRaw('LOWER(TRIM(source_site_name)) = ?', [$normalizedSource])
                    ->orWhere('source_site_key', $source)
                    ->orWhere('source_site_key', $sourceKey);
            });
        }

        if (!empty($validated['status'])) {
            $status = $validated['status'];
            if ($status === 'open') {
                $query->where(function ($builder): void {
                    $builder->where(function ($q): void {
                        $q->whereRaw("LOWER(COALESCE(source_status, '')) like ?", ['%open%'])
                            ->orWhereRaw("LOWER(COALESCE(source_status, '')) like ?", ['%active%'])
                            ->orWhere(function ($statusQ): void {
                                $statusQ->whereNull('source_status')
                                    ->orWhereRaw("TRIM(COALESCE(source_status, '')) = ''")
                                    ->orWhere(function ($activeStatus): void {
                                        $activeStatus
                                            ->whereRaw("LOWER(source_status) not like ?", ['%award%'])
                                            ->whereRaw("LOWER(source_status) not like ?", ['%closed%'])
                                            ->whereRaw("LOWER(source_status) not like ?", ['%cancel%'])
                                            ->whereRaw("LOWER(source_status) not like ?", ['%expired%'])
                                            ->whereRaw("LOWER(source_status) not like ?", ['%complete%']);
                                    });
                            });
                    })->where(function ($inner): void {
                        $inner->whereNull('date_closing_at')
                            ->orWhere('date_closing_at', '>', now());
                    });
                });
            } elseif ($status === 'awarded') {
                $query->where('source_status', 'like', '%award%');
            } elseif ($status === 'expired') {
                $query->where(function ($builder): void {
                    $builder->where('source_status', 'like', '%closed%')
                        ->orWhere('source_status', 'like', '%cancel%')
                        ->orWhere('source_status', 'like', '%expired%')
                        ->orWhere('source_status', 'like', '%complete%')
                        ->orWhere(function ($q): void {
                            $q->whereNotNull('date_closing_at')
                                ->where('date_closing_at', '<=', now())
                                ->where(function ($inner): void {
                                    $inner->whereNull('source_status')
                                        ->orWhere('source_status', 'not like', '%award%');
                                });
                        });
                });
            }
        }

        try {
            $results = $query
                ->orderByDesc('published_at')
                ->paginate(6);
        } catch (Throwable) {
            $currentPage = (int) ($validated['page'] ?? 1);
            $results = new LengthAwarePaginator([], 0, 6, max(1, $currentPage), [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
        }

        return response()->json($results);
    }

    public function sources(): JsonResponse
    {
        try {
            $sources = $this->publicProjectsQuery()
                ->whereNotNull('source_site_name')
                ->distinct()
                ->orderBy('source_site_name')
                ->pluck('source_site_name')
                ->values();
        } catch (Throwable) {
            $sources = collect();
        }

        return response()->json([
            'data' => $sources,
        ]);
    }
}
