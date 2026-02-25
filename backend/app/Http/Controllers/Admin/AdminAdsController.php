<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdSpot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAdsController extends Controller
{
    private const DEFAULT_SPOTS = [
        [
            'key' => 'header',
            'name' => 'Header Banner',
            'placement' => 'header',
        ],
        [
            'key' => 'footer',
            'name' => 'Footer Banner',
            'placement' => 'footer',
        ],
    ];

    public function index(): JsonResponse
    {
        $this->ensureDefaultSpots();

        $spots = AdSpot::query()->orderBy('placement')->get();

        return response()->json([
            'data' => $spots,
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
            'embed_code' => ['nullable', 'string'],
        ]);

        $spot = AdSpot::query()->where('key', $key)->first();
        if (!$spot) {
            return response()->json([
                'message' => 'Unknown ad spot key.',
            ], 404);
        }

        $spot->fill($validated);
        $spot->save();

        return response()->json([
            'data' => $spot,
        ]);
    }

    private function ensureDefaultSpots(): void
    {
        foreach (self::DEFAULT_SPOTS as $spot) {
            AdSpot::query()->firstOrCreate(
                ['key' => $spot['key']],
                [
                    'name' => $spot['name'],
                    'placement' => $spot['placement'],
                    'provider' => null,
                    'is_enabled' => false,
                    'settings' => null,
                    'embed_code' => null,
                ]
            );
        }
    }
}
