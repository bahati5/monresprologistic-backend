<?php

namespace App\Http\Controllers;

use App\Models\Consolidation;
use App\Models\CustomerPackage;
use App\Models\Pickup;
use App\Models\PreAlert;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\Status;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SectionDashboardController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function inbound(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->hasAnyRole(['super_admin', 'agency_admin', 'operator']),
            403
        );

        $preQ = PreAlert::query();
        if (! $user->canAccessAllAgencies()) {
            $preQ->whereHas('user', fn ($u) => $u->where('agency_id', $user->agency_id));
        }

        $pkgQ = CustomerPackage::query();
        if (! $user->canAccessAllAgencies()) {
            $pkgQ->where('agency_id', $user->agency_id);
        }

        $poQ = PurchaseOrder::query();
        if (! $user->canAccessAllAgencies()) {
            $poQ->where('agency_id', $user->agency_id);
        }

        $paymentCodes = ['payment_pending', 'pending_payment'];
        $awaitingPaymentIds = Status::query()->whereIn('code', $paymentCodes)->pluck('id');

        $stats = [
            'pre_alerts_month' => (clone $preQ)->where('created_at', '>=', now()->startOfMonth())->count(),
            'packages_received_today' => (clone $pkgQ)->whereDate('received_at', today())->count(),
            'awaiting_payment' => $awaitingPaymentIds->isEmpty()
                ? 0
                : (clone $pkgQ)->whereIn('status_id', $awaitingPaymentIds)->count(),
            'purchase_orders_pending' => (clone $poQ)
                ->whereHas('status', fn ($s) => $s->whereIn('code', ['created', 'quoted', 'paid', 'purchasing']))
                ->count(),
        ];

        $start = now()->subDays(29)->startOfDay();
        $daily_arrivals = [];
        for ($i = 0; $i < 30; $i++) {
            $dayStart = $start->copy()->addDays($i)->startOfDay();
            $dayEnd = $dayStart->copy()->endOfDay();
            $daily_arrivals[] = [
                'day' => $dayStart->format('d/m'),
                'count' => (clone $pkgQ)->whereBetween('received_at', [$dayStart, $dayEnd])->count(),
            ];
        }

        $distRows = (clone $pkgQ)
            ->select('customer_packages.status_id', DB::raw('COUNT(*) as c'))
            ->whereNotNull('customer_packages.status_id')
            ->groupBy('customer_packages.status_id')
            ->get();

        $status_distribution = $distRows->map(function ($row) {
            $st = Status::query()->find($row->status_id);

            return [
                'name' => $st?->name ?? '—',
                'value' => (int) $row->c,
                'color' => $st?->color_hex,
            ];
        })->values()->all();

        return response()->json([
            'stats' => $stats,
            'daily_arrivals' => $daily_arrivals,
            'status_distribution' => $status_distribution,
        ]);
    }

    public function shipments(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->hasAnyRole(['super_admin', 'agency_admin', 'operator', 'customs_agent']),
            403
        );

        $base = Shipment::query();
        $this->scopeShipmentsFor($base, $user);

        $activeCodes = ['created', 'accepted', 'in_preparation', 'collected', 'in_transit', 'in_customs', 'arrived', 'out_for_delivery'];
        $inTransitCodes = ['in_transit', 'in_customs'];

        $active = (clone $base)->whereHas('status', fn ($s) => $s->whereIn('code', $activeCodes))->count();
        $created_today = (clone $base)->whereDate('created_at', today())->count();
        $in_transit = (clone $base)->whereHas('status', fn ($s) => $s->whereIn('code', $inTransitCodes))->count();

        $startMonth = now()->startOfMonth();
        $delivered_month = (clone $base)
            ->whereHas('status', fn ($s) => $s->where('code', 'delivered'))
            ->where('updated_at', '>=', $startMonth)
            ->count();

        $total_done = (clone $base)->whereHas('status', fn ($s) => $s->where('code', 'delivered'))->count();
        $delivery_rate = $total_done + $active > 0 ? (int) round(100 * $total_done / max(1, $total_done + $active)) : 0;

        $deliveredSample = (clone $base)
            ->whereHas('status', fn ($s) => $s->where('code', 'delivered'))
            ->whereNotNull('created_at')
            ->whereNotNull('updated_at')
            ->latest('updated_at')
            ->limit(400)
            ->get(['created_at', 'updated_at']);
        $avg_days = $deliveredSample->isEmpty()
            ? 0
            : round($deliveredSample->avg(fn (Shipment $s) => $s->created_at->diffInDays($s->updated_at)), 1);

        $weekStart = now()->startOfWeek()->subWeeks(11)->startOfDay();
        $weekly_evolution = [];
        for ($w = 0; $w < 12; $w++) {
            $ws = $weekStart->copy()->addWeeks($w);
            $we = $ws->copy()->endOfWeek();
            $weekly_evolution[] = [
                'week' => 'S'.$ws->isoWeek(),
                'count' => (clone $base)->whereBetween('created_at', [$ws, $we])->count(),
            ];
        }

        return response()->json([
            'stats' => [
                'active' => $active,
                'created_today' => $created_today,
                'in_transit' => $in_transit,
                'delivered_month' => $delivered_month,
                'delivery_rate' => $delivery_rate,
                'avg_days' => round($avg_days, 1),
            ],
            'weekly_evolution' => $weekly_evolution,
        ]);
    }

    public function pickups(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->hasAnyRole(['super_admin', 'agency_admin', 'operator', 'driver']),
            403
        );

        $q = Pickup::query();
        if (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }

        if ($user->hasRole('driver')) {
            $q->where('assigned_driver_id', $user->id);
        }

        $stats = [
            'today' => (clone $q)->whereDate('created_at', today())->count(),
            'week' => (clone $q)->where('created_at', '>=', now()->subDays(7))->count(),
            'open' => (clone $q)->whereDoesntHave('status', fn ($s) => $s->whereIn('code', ['pickup_collected', 'pickup_at_hub']))->count(),
        ];

        return response()->json([
            'stats' => $stats,
        ]);
    }

    public function consolidations(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('view_consolidations'), 403);

        $q = Consolidation::query();
        $this->scopeByAgency($q, $user);

        $stats = [
            'open' => (clone $q)->whereDoesntHave('status', fn ($s) => $s->where('code', 'cons_distributed'))->count(),
            'this_month' => (clone $q)->where('created_at', '>=', now()->startOfMonth())->count(),
        ];

        return response()->json([
            'stats' => $stats,
        ]);
    }

    public function crm(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->hasAnyRole(['super_admin', 'agency_admin', 'operator'])
                || $user->can('manage_clients')
                || $user->can('manage_drivers'),
            403
        );

        $recipientsQ = \App\Models\Recipient::query();
        if (! $user->canAccessAllAgencies()) {
            $recipientsQ->where(function ($q) use ($user) {
                $q->whereHas('user', fn ($u) => $u->where('agency_id', $user->agency_id))
                    ->orWhereHas('crmClient', fn ($c) => $c->where('agency_id', $user->agency_id));
            });
        }

        $stats = [
            'recipients' => (clone $recipientsQ)->count(),
        ];

        $showClients = $user->can('manage_clients');
        if ($showClients) {
            $clientsQ = \App\Models\CrmClient::query();
            if (! $user->canAccessAllAgencies()) {
                $clientsQ->where('agency_id', $user->agency_id);
            }
            $stats['clients'] = (clone $clientsQ)->count();
        }

        $showDrivers = $user->can('manage_drivers');
        if ($showDrivers) {
            $driversQ = User::query()->role('driver');
            if (! $user->canAccessAllAgencies()) {
                $driversQ->where('agency_id', $user->agency_id);
            }
            $stats['drivers'] = (clone $driversQ)->count();
        }

        return response()->json([
            'stats' => $stats,
            'showDrivers' => $showDrivers,
            'showClients' => $showClients,
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('view_reports'), 403);

        return response()->json([]);
    }
}
