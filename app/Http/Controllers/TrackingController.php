<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use Illuminate\Http\JsonResponse;

class TrackingController extends Controller
{
    public function show(string $publicTracking): JsonResponse
    {
        $shipment = Shipment::query()
            ->where('public_tracking', $publicTracking)
            ->with([
                'currentHub',
                'logs' => fn ($q) => $q->with('user')->orderByDesc('created_at'),
            ])
            ->firstOrFail();

        return response()->json([
            'shipment' => $shipment,
        ]);
    }
}
