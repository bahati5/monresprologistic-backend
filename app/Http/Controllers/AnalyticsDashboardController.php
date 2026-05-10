<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\AssistedPurchase;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsDashboardController extends Controller
{
    public function analytics(Request $request): JsonResponse
    {
        $days = (int) ($request->query('days', 30));
        $from = now()->subDays($days);
        $user = $request->user();

        $agencyFilter = fn ($q) => $user->canAccessAllAgencies()
            ? $q
            : $q->where('agency_id', $user->agency_id);

        $shipmentsTotal = $agencyFilter(Shipment::query()->excludingDrafts()->where('created_at', '>=', $from))->count();
        $shipmentsDelivered = $agencyFilter(Shipment::query()->where('created_at', '>=', $from)->where('status', ShipmentStatus::Delivered))->count();

        $revenue = $agencyFilter(Shipment::query()->excludingDrafts()->where('created_at', '>=', $from))->sum('calculated_price');

        $purchasesTotal = AssistedPurchase::where('created_at', '>=', $from)->count();

        $byStatus = $agencyFilter(Shipment::query()->excludingDrafts())
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->status->value => $r->count]);

        $weeklyVolume = $agencyFilter(Shipment::query()->excludingDrafts()->where('created_at', '>=', now()->subWeeks(12)))
            ->select(DB::raw('YEARWEEK(created_at) as yw'), DB::raw('COUNT(*) as count'))
            ->groupBy('yw')
            ->orderBy('yw')
            ->get();

        $topClients = $agencyFilter(Shipment::query()->excludingDrafts()->where('created_at', '>=', $from))
            ->select('creator_user_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(calculated_price) as revenue'))
            ->groupBy('creator_user_id')
            ->orderByDesc('revenue')
            ->limit(10)
            ->with('creator:id,name')
            ->get();

        $destDistribution = $agencyFilter(Shipment::query()->excludingDrafts()->where('created_at', '>=', $from))
            ->select('dest_country_id', DB::raw('COUNT(*) as count'))
            ->groupBy('dest_country_id')
            ->orderByDesc('count')
            ->limit(10)
            ->with('destCountry:id,name')
            ->get();

        return response()->json([
            'period_days' => $days,
            'shipments_total' => $shipmentsTotal,
            'shipments_delivered' => $shipmentsDelivered,
            'revenue' => round((float) $revenue, 2),
            'assisted_purchases' => $purchasesTotal,
            'by_status' => $byStatus,
            'weekly_volume' => $weeklyVolume,
            'top_clients' => $topClients,
            'dest_distribution' => $destDistribution,
        ]);
    }

    public function overdue(Request $request): JsonResponse
    {
        $thresholdDays = (int) ($request->query('threshold', 14));
        $cutoff = now()->subDays($thresholdDays);
        $user = $request->user();

        $overdueShipments = Shipment::query()
            ->excludingDrafts()
            ->whereNotIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::Cancelled->value])
            ->where('created_at', '<', $cutoff)
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->with(['creator:id,name', 'destCountry:id,name'])
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'threshold_days' => $thresholdDays,
            'overdue_count' => $overdueShipments->count(),
            'shipments' => $overdueShipments,
        ]);
    }
}
