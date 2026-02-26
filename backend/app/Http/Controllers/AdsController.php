<?php

namespace App\Http\Controllers;

use App\Models\AdSpot;
use Illuminate\Http\JsonResponse;

class AdsController extends Controller
{
    /**
     * Return enabled ad spots keyed by placement for the public frontend.
     *
     * GET /api/ads/active
     */
    public function active(): JsonResponse
    {
        $spots = AdSpot::query()
            ->where('is_enabled', true)
            ->whereNotNull('embed_code')
            ->where('embed_code', '!=', '')
            ->get(['key', 'placement', 'is_enabled', 'embed_code']);

        $payload = [];

        foreach ($spots as $spot) {
            $payload[$spot->key] = [
                'enabled'    => $spot->is_enabled,
                'embed_code' => $spot->embed_code,
            ];
        }

        return response()->json($payload);
    }
}
