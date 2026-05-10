<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Services\ShipmentWorkflowService;
use Illuminate\Http\JsonResponse;

class TrackingController extends Controller
{
    public function __construct(
        private readonly ShipmentWorkflowService $workflowService,
    ) {}

    public function show(string $publicTracking): JsonResponse
    {
        $shipment = Shipment::query()
            ->where('public_tracking', $publicTracking)
            ->with([
                'originCountry',
                'destCountry',
                'currentHub',
                'logs' => fn ($q) => $q->with('user')->orderByDesc('created_at'),
            ])
            ->firstOrFail();

        $steps = $this->workflowService->buildStepsForShipment($shipment);

        $originCountry = $shipment->originCountry?->name;
        $destCountry = $shipment->destCountry?->name;
        if (is_array($originCountry)) {
            $originCountry = $originCountry['fr'] ?? $originCountry['en'] ?? reset($originCountry);
        }
        if (is_array($destCountry)) {
            $destCountry = $destCountry['fr'] ?? $destCountry['en'] ?? reset($destCountry);
        }

        return response()->json([
            'tracking_number' => $shipment->public_tracking,
            'status' => [
                'code' => $shipment->status?->value,
                'label' => $shipment->status?->label(),
            ],
            'origin_country' => $originCountry,
            'destination_country' => $destCountry,
            'created_at' => $shipment->created_at?->toIso8601String(),
            'estimated_arrival' => $shipment->service_options['estimated_arrival'] ?? null,
            'steps' => $steps,
        ]);
    }

    /**
     * JSON API endpoint for WordPress widget and external consumers.
     */
    public function apiTrack(string $trackingNumber): JsonResponse
    {
        return $this->show($trackingNumber);
    }

    /**
     * §17 — Endpoint public CORS-enabled pour le widget WordPress.
     * Retourne le strict minimum pour l'affichage dans le widget embeddable.
     */
    public function widgetTrack(string $trackingNumber): JsonResponse
    {
        try {
            $shipment = Shipment::query()
                ->where('public_tracking', $trackingNumber)
                ->with(['originCountry', 'destCountry'])
                ->firstOrFail();

            $destCountry = $shipment->destCountry?->name;
            if (is_array($destCountry)) {
                $destCountry = $destCountry['fr'] ?? $destCountry['en'] ?? reset($destCountry);
            }

            return response()->json([
                'found' => true,
                'tracking_number' => $shipment->public_tracking,
                'status_code' => $shipment->status?->value,
                'status_label' => $shipment->status?->label(),
                'destination' => $destCountry,
                'estimated_arrival' => $shipment->service_options['estimated_arrival'] ?? null,
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET')
              ->header('Access-Control-Allow-Headers', 'Content-Type');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['found' => false])
                ->header('Access-Control-Allow-Origin', '*');
        }
    }
}
