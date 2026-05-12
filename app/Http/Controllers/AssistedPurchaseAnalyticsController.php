<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\QuoteSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssistedPurchaseAnalyticsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->get('from', now()->subDays(30)->toDateString());
        $to = $request->get('to', now()->toDateString());

        $baseQuery = AssistedPurchase::query()
            ->whereNotNull('quoted_at')
            ->whereBetween('quoted_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

        if (!$user->canAccessAllAgencies()) {
            $baseQuery->whereHas('user', fn ($q) => $q->where('agency_id', $user->agency_id));
        }

        $totalQuoted = (clone $baseQuery)->count();

        $acceptedStatuses = [
            AssistedPurchaseStatus::PAID->value,
            AssistedPurchaseStatus::ORDERED->value,
            AssistedPurchaseStatus::ARRIVED_AT_HUB->value,
            AssistedPurchaseStatus::CONVERTED_TO_SHIPMENT->value,
        ];

        $totalAccepted = (clone $baseQuery)
            ->whereIn('status', $acceptedStatuses)
            ->count();

        $acceptanceRate = $totalQuoted > 0
            ? round(($totalAccepted / $totalQuoted) * 100, 1)
            : 0;

        $totalRevenue = (clone $baseQuery)
            ->whereIn('status', $acceptedStatuses)
            ->sum('total_amount');

        $avgResponseHours = QuoteSnapshot::whereNotNull('responded_at')
            ->whereNotNull('sent_at')
            ->whereBetween('sent_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, sent_at, responded_at)) as avg_hours')
            ->value('avg_hours') ?? 0;

        $weeklyData = (clone $baseQuery)
            ->selectRaw('YEARWEEK(quoted_at, 1) as yw, COUNT(*) as quoted_count')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as accepted_count', $acceptedStatuses)
            ->groupByRaw('YEARWEEK(quoted_at, 1)')
            ->orderBy('yw')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'week' => $row->yw,
                'quoted' => $row->quoted_count,
                'accepted' => $row->accepted_count,
            ]);

        $topMerchants = AssistedPurchaseItem::query()
            ->join('assisted_purchases', 'assisted_purchases.id', '=', 'assisted_purchase_items.assisted_purchase_id')
            ->whereNotNull('assisted_purchases.quoted_at')
            ->whereBetween('assisted_purchases.quoted_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->join('merchants', 'merchants.id', '=', 'assisted_purchase_items.merchant_id')
            ->select('merchants.name', DB::raw('COUNT(*) as count'))
            ->groupBy('merchants.name')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        $refusalReasons = (clone $baseQuery)
            ->where('status', AssistedPurchaseStatus::CANCELLED)
            ->whereNotNull('refusal_reason')
            ->select('refusal_reason', DB::raw('COUNT(*) as count'))
            ->groupBy('refusal_reason')
            ->orderByDesc('count')
            ->get();

        $reminderEfficiency = [
            'after_reminder_1' => QuoteSnapshot::where('client_response', 'accepted')
                ->whereHas('assistedPurchase', fn ($q) => $q->where('reminder_count', 1)
                    ->whereBetween('quoted_at', [$from . ' 00:00:00', $to . ' 23:59:59']))
                ->count(),
            'after_reminder_2' => QuoteSnapshot::where('client_response', 'accepted')
                ->whereHas('assistedPurchase', fn ($q) => $q->where('reminder_count', 2)
                    ->whereBetween('quoted_at', [$from . ' 00:00:00', $to . ' 23:59:59']))
                ->count(),
        ];

        $clarificationRate = (clone $baseQuery)
            ->whereNotNull('clarification_sent_at')
            ->count();

        return response()->json([
            'total_quoted' => $totalQuoted,
            'total_accepted' => $totalAccepted,
            'acceptance_rate' => $acceptanceRate,
            'total_revenue' => round((float) $totalRevenue, 2),
            'avg_response_hours' => round((float) $avgResponseHours, 1),
            'weekly_data' => $weeklyData,
            'top_merchants' => $topMerchants,
            'refusal_reasons' => $refusalReasons,
            'reminder_efficiency' => $reminderEfficiency,
            'clarification_count' => $clarificationRate,
            'period' => ['from' => $from, 'to' => $to],
        ]);
    }
}
