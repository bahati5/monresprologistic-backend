<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Models\AssistedPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * §19 — Analytics : KPIs avancés (taux de conversion devis, etc.).
 */
class AnalyticsController extends Controller
{
    /**
     * §19 — Taux de conversion des devis achat assisté :
     * ratio Acceptés / (Acceptés + Refusés + Expirés) sur une période donnée.
     */
    public function quoteConversion(Request $request): JsonResponse
    {
        $user = $request->user();

        $from = $request->input('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();

        $to = $request->input('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        $query = AssistedPurchase::query()
            ->whereBetween('created_at', [$from, $to]);

        if (! $user->canAccessAllAgencies()) {
            $query->whereHas('user', fn ($q) => $q->where('agency_id', $user->agency_id));
        }

        $all = $query->get(['status', 'created_at', 'quoted_at']);

        $total          = $all->count();
        $pending        = $all->whereIn('status', [
            AssistedPurchaseStatus::PENDING_QUOTE->value,
            AssistedPurchaseStatus::QUOTED->value,
            AssistedPurchaseStatus::AWAITING_PAYMENT->value,
        ])->count();
        $accepted       = $all->whereIn('status', [
            AssistedPurchaseStatus::PAYMENT_RECEIVED->value,
            AssistedPurchaseStatus::PROCESSING->value,
            AssistedPurchaseStatus::SHIPPED->value,
            AssistedPurchaseStatus::COMPLETED->value,
        ])->count();
        $refused        = $all->where('status', AssistedPurchaseStatus::REFUSED->value)->count();
        $expired        = $all->where('status', AssistedPurchaseStatus::EXPIRED->value)->count();
        $failed         = $all->where('status', AssistedPurchaseStatus::FAILED->value)->count();

        $decided        = $accepted + $refused + $expired;
        $conversionRate = $decided > 0 ? round(($accepted / $decided) * 100, 1) : null;

        // Répartition par semaine (courbe d'évolution)
        $weekly = $all->groupBy(fn ($p) => Carbon::parse($p->created_at)->startOfWeek()->toDateString())
            ->map(fn ($group) => [
                'total'    => $group->count(),
                'accepted' => $group->whereIn('status', [
                    AssistedPurchaseStatus::PAYMENT_RECEIVED->value,
                    AssistedPurchaseStatus::PROCESSING->value,
                    AssistedPurchaseStatus::SHIPPED->value,
                    AssistedPurchaseStatus::COMPLETED->value,
                ])->count(),
                'refused'  => $group->where('status', AssistedPurchaseStatus::REFUSED->value)->count(),
                'expired'  => $group->where('status', AssistedPurchaseStatus::EXPIRED->value)->count(),
            ])->sortKeys();

        return response()->json([
            'period'            => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total'             => $total,
            'pending'           => $pending,
            'accepted'          => $accepted,
            'refused'           => $refused,
            'expired'           => $expired,
            'failed'            => $failed,
            'conversion_rate'   => $conversionRate,
            'weekly_breakdown'  => $weekly,
        ]);
    }
}
