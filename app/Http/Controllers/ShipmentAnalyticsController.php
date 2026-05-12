<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentAnalyticsController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->input('period', 'month');

        $start = match ($period) {
            'week' => now()->startOfWeek(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        $base = Shipment::query();
        $this->scopeShipmentsForUser($base, $user);
        $base->excludingDrafts();

        $periodBase = (clone $base)->where('shipments.created_at', '>=', $start);

        $total = (clone $periodBase)->count();
        $delivered = (clone $periodBase)->where('status', ShipmentStatus::Delivered)->count();
        $inTransit = (clone $periodBase)->where('status', ShipmentStatus::InTransit)->count();
        $deliveryRate = $total > 0 ? round(100 * $delivered / $total) : 0;

        $avgTransitDays = (clone $base)
            ->where('status', ShipmentStatus::Delivered)
            ->where('shipments.created_at', '>=', $start)
            ->whereNotNull('shipments.updated_at')
            ->selectRaw('AVG(DATEDIFF(shipments.updated_at, shipments.created_at)) as avg_days')
            ->value('avg_days') ?? 0;

        $byStatus = (clone $periodBase)
            ->select('shipments.status', DB::raw('COUNT(*) as count'))
            ->groupBy('shipments.status')
            ->get();

        $byDestCountry = (clone $periodBase)
            ->join('countries', 'countries.id', '=', 'shipments.destination_country_id')
            ->select('countries.name as country', DB::raw('COUNT(*) as count'))
            ->groupBy('countries.id', 'countries.name')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $weeklyEvolution = [];
        for ($w = 11; $w >= 0; $w--) {
            $ws = now()->startOfWeek()->subWeeks($w);
            $we = $ws->copy()->endOfWeek();
            $weeklyEvolution[] = [
                'week' => 'S' . $ws->isoWeek(),
                'created' => (clone $base)->whereBetween('shipments.created_at', [$ws, $we])->count(),
                'delivered' => (clone $base)->where('status', ShipmentStatus::Delivered)->whereBetween('shipments.updated_at', [$ws, $we])->count(),
            ];
        }

        return response()->json([
            'kpis' => [
                'total' => $total,
                'delivered' => $delivered,
                'in_transit' => $inTransit,
                'delivery_rate' => $deliveryRate,
                'avg_transit_days' => round($avgTransitDays, 1),
            ],
            'by_status' => $byStatus,
            'by_destination' => $byDestCountry,
            'weekly_evolution' => $weeklyEvolution,
        ]);
    }
}
