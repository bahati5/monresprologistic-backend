<?php

namespace App\Http\Controllers;

use App\Models\Shipment;

class TrackingController extends Controller
{
    public function show(string $publicTracking): JsonResponse
    {
        $shipment = Shipment::query()
            ->where('public_tracking', $publicTracking)
            ->with(['status', 'currentHub', 'logs' => fn ($q) => $q->latest('created_at')])
            ->firstOrFail();

        return response()->json([
            'shipment' => $shipment,
        ]);
    }
}
