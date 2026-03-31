<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceDashboardController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_finances'), 403);

        $user = $request->user();
        $baseQ = Invoice::query();
        if (! $user->canAccessAllAgencies()) {
            $baseQ->whereHas('shipment', fn ($s) => $s->where('agency_id', $user->agency_id));
        }

        $thisMonth = now()->startOfMonth();
        $endMonth = now()->endOfMonth();

        $invoicedThisMonth = (clone $baseQ)->whereBetween('created_at', [$thisMonth, $endMonth])->sum('amount');
        $paidThisMonth = (clone $baseQ)->where('status', 'paid')->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$thisMonth, $endMonth])
            ->sum('amount');
        $totalPaid = (clone $baseQ)->where('status', 'paid')->sum('amount');
        $totalPending = (clone $baseQ)->whereIn('status', ['open', 'partial'])->sum('amount');

        $byStatus = (clone $baseQ)
            ->selectRaw('status, count(*) as count, sum(amount) as total')
            ->groupBy('status')
            ->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count, 'total' => (float) $r->total])
            ->values()
            ->all();

        return response()->json([
            'stats' => [
                'invoiced_this_month' => round($invoicedThisMonth, 2),
                'paid_this_month' => round($paidThisMonth, 2),
                'total_pending' => round($totalPending, 2),
                'total_paid' => round($totalPaid, 2),
            ],
            'by_status' => $byStatus,
        ]);
    }
}
