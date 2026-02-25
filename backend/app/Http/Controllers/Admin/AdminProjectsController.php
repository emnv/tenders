<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AdminProjectsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'featured' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Project::query();

        if (!empty($validated['q'])) {
            $keyword = $validated['q'];
            $query->where(function ($builder) use ($keyword): void {
                $builder
                    ->where('title', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%")
                    ->orWhere('location', 'like', "%{$keyword}%")
                    ->orWhere('source_site_name', 'like', "%{$keyword}%");
            });
        }

        if (!empty($validated['source'])) {
            $query->where('source_site_name', $validated['source']);
        }

        if (array_key_exists('featured', $validated)) {
            $query->where('is_featured', (bool) $validated['featured']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);

        $results = $query
            ->orderByDesc('published_at')
            ->paginate($perPage);

        return response()->json($results);
    }

    public function updateFeatured(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'is_featured' => ['required', 'boolean'],
        ]);

        $project->is_featured = (bool) $validated['is_featured'];
        $project->save();

        return response()->json([
            'data' => $project->fresh(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'source_site_name' => ['nullable', 'string', 'max:100'],
            'source_url' => ['nullable', 'string', 'max:255'],
            'source_status' => ['nullable', 'string', 'max:50'],
            'date_closing_at' => ['nullable', 'date'],
            'published_at' => ['nullable', 'date'],
        ]);

        $project = Project::create(array_merge($validated, [
            'is_manual_entry' => true,
        ]));

        return response()->json([
            'data' => $project->fresh(),
        ], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'source_site_name' => ['nullable', 'string', 'max:100'],
            'source_url' => ['nullable', 'string', 'max:255'],
            'source_status' => ['nullable', 'string', 'max:50'],
            'date_closing_at' => ['nullable', 'date'],
            'published_at' => ['nullable', 'date'],
        ]);

        $project->fill(Arr::except($validated, ['id']));
        $project->save();

        return response()->json([
            'data' => $project->fresh(),
        ]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $project->delete();

        return response()->json([
            'message' => 'Project deleted.',
        ]);
    }
}
