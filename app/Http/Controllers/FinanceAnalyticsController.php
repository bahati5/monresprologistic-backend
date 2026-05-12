<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceAnalyticsController extends Controller
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

        $invBase = Invoice::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id));

        $periodInv = (clone $invBase)->where('created_at', '>=', $start);

        $totalInvoiced = (float) (clone $periodInv)->sum('amount');
        $totalPaid = (float) (clone $periodInv)->where('status', 'paid')->sum('amount');
        $totalPending = (float) (clone $periodInv)->whereIn('status', ['pending', 'sent'])->sum('amount');
        $totalOverdue = (float) (clone $periodInv)->where('status', '!=', 'paid')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->sum('amount');

        $collectionRate = $totalInvoiced > 0 ? round(100 * $totalPaid / $totalInvoiced, 1) : 0;

        $refundsTotal = (float) Refund::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->where('created_at', '>=', $start)
            ->sum('amount');

        $monthlyRevenue = [];
        for ($m = 5; $m >= 0; $m--) {
            $ms = now()->subMonthsNoOverflow($m)->startOfMonth();
            $me = $ms->copy()->endOfMonth();
            $monthlyRevenue[] = [
                'month' => $ms->locale('fr')->translatedFormat('M Y'),
                'invoiced' => round((float) (clone $invBase)->whereBetween('created_at', [$ms, $me])->sum('amount'), 2),
                'collected' => round((float) (clone $invBase)->where('status', 'paid')->whereBetween('paid_at', [$ms, $me])->sum('amount'), 2),
            ];
        }

        $byService = (clone $periodInv)
            ->select(
                DB::raw("CASE WHEN shipment_id IS NOT NULL THEN 'expedition' ELSE 'other' END as service"),
                DB::raw('SUM(amount) as total'),
            )
            ->groupBy('service')
            ->get();

        $avgPaymentDays = (clone $periodInv)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->selectRaw('AVG(DATEDIFF(paid_at, created_at)) as avg_days')
            ->value('avg_days') ?? 0;

        $topClients = DB::table('invoices')
            ->join('users', 'users.id', '=', 'invoices.user_id')
            ->where('invoices.created_at', '>=', $start)
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('invoices.agency_id', $user->agency_id))
            ->select(
                'users.id',
                'users.name',
                DB::raw('SUM(invoices.amount) as total_amount'),
                DB::raw('COUNT(*) as invoice_count'),
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        return response()->json([
            'kpis' => [
                'total_invoiced' => round($totalInvoiced, 2),
                'total_paid' => round($totalPaid, 2),
                'total_pending' => round($totalPending, 2),
                'total_overdue' => round($totalOverdue, 2),
                'collection_rate' => $collectionRate,
                'refunds' => round($refundsTotal, 2),
                'avg_payment_days' => round($avgPaymentDays, 1),
            ],
            'monthly_revenue' => $monthlyRevenue,
            'by_service' => $byService,
            'top_clients' => $topClients,
        ]);
    }
}
