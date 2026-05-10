<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\AssistedPurchase;
use App\Models\CustomerPackage;
use App\Models\Hub;
use App\Models\Invoice;
use App\Models\Locker;
use App\Models\PaymentProof;
use App\Models\Pickup;
use App\Models\PreAlert;
use App\Models\Profile;
use App\Models\Shipment;
use App\Models\ShipmentLog;
use App\Models\User;
use App\Services\ShipmentWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('driver')) {
            $pickupsToday = Pickup::query()
                ->where('assigned_driver_id', $user->id)
                ->whereDate('created_at', today())
                ->with('client')
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'address' => $p->address_text ?? '',
                    'scheduled_at' => $p->requested_window,
                    'status' => $p->status?->value ?? 'draft',
                    'client' => $p->client ? ['name' => $p->client->name, 'phone' => $p->client->phone] : null,
                    'latitude' => (float) $p->latitude,
                    'longitude' => (float) $p->longitude,
                ]);

            $deliveriesToday = Shipment::query()
                ->where('assigned_driver_id', $user->id)
                ->with(['recipientProfile'])
                ->latest()
                ->take(20)
                ->get();

            $mapPoints = $pickupsToday->filter(fn ($p) => isset($p['latitude'], $p['longitude']))->map(fn ($p) => [
                'lat' => $p['latitude'],
                'lng' => $p['longitude'],
                'label' => $p['client']['name'] ?? 'Pickup',
                'type' => 'pickup',
            ])->values()->all();

            return response()->json([
                'dashboard_type' => 'driver',
                'stats' => [
                    'pickups_pending' => Pickup::where('assigned_driver_id', $user->id)->count(),
                    'deliveries_pending' => Shipment::where('assigned_driver_id', $user->id)->whereIn('status', [
                        ShipmentStatus::InTransit,
                        ShipmentStatus::ArrivedAtDestination,
                    ])->count(),
                    'completed_today' => Shipment::where('assigned_driver_id', $user->id)->where('status', ShipmentStatus::Delivered)->whereDate('updated_at', today())->count(),
                ],
                'pickups_today' => $pickupsToday,
                'deliveries_today' => $deliveriesToday,
                'map_points' => $mapPoints,
            ]);
        }

        if ($user->hasRole('client')) {
            $profileId = $user->profile_id;
            $shipments = Shipment::query()
                ->where(function ($q) use ($user, $profileId) {
                    $q->where('creator_user_id', $user->id);
                    if ($profileId) {
                        $q->orWhere('sender_profile_id', $profileId);
                    }
                })
                ->latest()
                ->take(8)
                ->get();

            $locker = Locker::query()
                ->where('user_id', $user->id)
                ->first();

            $shipmentCountQ = Shipment::query()
                ->where(function ($q) use ($user, $profileId) {
                    $q->where('creator_user_id', $user->id);
                    if ($profileId) {
                        $q->orWhere('sender_profile_id', $profileId);
                    }
                });

            $preAlertsCount = PreAlert::query()->where('user_id', $user->id)->count();

            return response()->json([
                'dashboard_type' => 'client',
                'locker' => $locker,
                'shipments' => $shipments,
                'preAlertsCount' => $preAlertsCount,
                'assistedCount' => AssistedPurchase::query()->where('user_id', $user->id)->count(),
                'stats' => [
                    'pre_alerts' => $preAlertsCount,
                    'purchase_orders' => AssistedPurchase::query()->where('user_id', $user->id)->count(),
                    'shipments_total' => (clone $shipmentCountQ)->excludingDrafts()->count(),
                ],
            ]);
        }

        if ($user->hasRole('customs_agent')) {
            $customsShipments = Shipment::query()
                ->where('status', ShipmentStatus::InTransit)
                ->with(['senderProfile', 'recipientProfile'])
                ->latest()
                ->take(20)
                ->get();

            return response()->json([
                'dashboard_type' => 'customs',
                'stats' => [
                    'in_customs' => Shipment::where('status', ShipmentStatus::InTransit)->count(),
                    'cleared_today' => Shipment::where('status', ShipmentStatus::ArrivedAtDestination)
                        ->whereDate('updated_at', today())->count(),
                    'pending_docs' => 0,
                ],
                'customs_shipments' => $customsShipments,
            ]);
        }

        if ($user->hasRole('operator')) {
            $shipmentsQ = Shipment::query();
            $this->scopeShipmentsForUser($shipmentsQ, $user);

            $packagesToday = CustomerPackage::query();
            if (! $user->canAccessAllAgencies()) {
                $packagesToday->where('agency_id', $user->agency_id);
            }
            $packagesTodayCount = (clone $packagesToday)->whereDate('received_at', today())->count();

            $payload = $this->buildStaffDashboardPayload($user, 'operator', $shipmentsQ);
            $payload['stats']['packages_today'] = $packagesTodayCount;

            return response()->json($payload);
        }

        $shipmentsQ = Shipment::query();
        $this->scopeShipmentsForUser($shipmentsQ, $user);

        return response()->json($this->buildStaffDashboardPayload($user, 'admin', $shipmentsQ));
    }

    /**
     * @param  Builder<Shipment>  $shipmentsQ
     */
    protected function buildStaffDashboardPayload(User $user, string $dashboardType, Builder $shipmentsQ): array
    {
        $preAlertsPending = $this->scopedPreAlerts($user)->count();
        $pickupsToday = $this->scopedPickups($user)->whereDate('created_at', today())->count();
        $paymentProofsPending = PaymentProof::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->whereHas('invoice.shipment', fn ($s) => $s->where('agency_id', $user->agency_id)))
            ->where('status', 'pending')
            ->count();

        // Fiches « client » = profils rattachés à une agence (comme ClientController), pas les profils staff sans agence.
        $clientsQ = Profile::query()
            ->whereNotNull('agency_id')
            ->where('is_active', true);
        if (! $user->canAccessAllAgencies()) {
            $clientsQ->where('agency_id', $user->agency_id);
        }
        $clientsCount = (clone $clientsQ)->count();

        $thisMonth = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = now()->subMonthNoOverflow()->endOfMonth();

        /** KPI et graphiques : hors brouillons */
        $shipmentsStatsQ = (clone $shipmentsQ)->excludingDrafts();

        $shipmentsThisMonth = (clone $shipmentsStatsQ)->where('created_at', '>=', $thisMonth)->count();
        $shipmentsLastMonth = (clone $shipmentsStatsQ)->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->count();

        $invBase = $this->scopedInvoices($user);
        $monthlyRevenue = (float) (clone $invBase)->where('status', 'paid')->whereNotNull('paid_at')
            ->where('paid_at', '>=', $thisMonth)
            ->sum('amount');
        $monthlyRevenueLast = (float) (clone $invBase)->where('status', 'paid')->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        $stats = [
            'shipments_total' => (clone $shipmentsStatsQ)->count(),
            'pre_alerts' => $preAlertsPending,
            'pre_alerts_pending' => $preAlertsPending,
            'pickups_count' => $pickupsToday,
            'pickups_today' => $pickupsToday,
            'payment_proofs_pending' => $paymentProofsPending,
            'monthly_revenue' => round($monthlyRevenue, 2),
            'clients_count' => $clientsCount,
            'shipments_trend' => $this->trendPercent((float) $shipmentsThisMonth, (float) $shipmentsLastMonth),
            'revenue_trend' => $this->trendPercent($monthlyRevenue, $monthlyRevenueLast),
        ];

        $payload = [
            'dashboard_type' => $dashboardType,
            'stats' => $stats,
            'charts' => [
                'monthly_evolution' => $this->buildMonthlyEvolution($user, $shipmentsStatsQ),
                'status_distribution' => $this->buildShipmentStatusDistribution($shipmentsStatsQ),
            ],
            'recent_activity' => $this->buildRecentActivity($shipmentsQ),
            'recent_shipments' => (clone $shipmentsQ)->with(['senderProfile', 'recipientProfile'])->latest()->take(10)->get(),
        ];

        if ($dashboardType === 'admin') {
            $payload['hubs'] = Hub::query()->orderBy('sort_order')->get()->map(fn ($h) => [
                'id' => $h->id,
                'code' => $h->code,
                'name' => $h->name,
                'latitude' => (float) $h->latitude,
                'longitude' => (float) $h->longitude,
            ]);
        }

        return $payload;
    }

    protected function scopedInvoices(User $user): Builder
    {
        $q = Invoice::query();
        if (! $user->canAccessAllAgencies()) {
            $q->whereHas('shipment', fn ($s) => $s->where('agency_id', $user->agency_id));
        }

        return $q;
    }

    protected function trendPercent(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @param  Builder<Shipment>  $shipmentsQ
     */
    protected function buildMonthlyEvolution(User $user, Builder $shipmentsQ): array
    {
        $rows = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = now()->subMonthsNoOverflow($i)->startOfMonth();
            $end = now()->subMonthsNoOverflow($i)->endOfMonth();

            $shipCount = (clone $shipmentsQ)->whereBetween('created_at', [$start, $end])->count();

            $invQ = $this->scopedInvoices($user);
            $revenue = (float) (clone $invQ)->where('status', 'paid')->whereNotNull('paid_at')
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount');

            $rows[] = [
                'month' => $start->copy()->locale('fr')->translatedFormat('M Y'),
                'shipments' => $shipCount,
                'revenue' => round($revenue, 2),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<Shipment>  $shipmentsQ
     */
    protected function buildShipmentStatusDistribution(Builder $shipmentsQ): array
    {
        $distRows = (clone $shipmentsQ)
            ->select('shipments.status', DB::raw('COUNT(*) as c'))
            ->whereNotNull('shipments.status')
            ->groupBy('shipments.status')
            ->get();

        $workflow = app(ShipmentWorkflowService::class);

        return $distRows->map(function ($row) use ($workflow) {
            $enum = ShipmentStatus::tryFromString($row->status);

            return [
                'name' => $enum?->label() ?? '—',
                'value' => (int) $row->c,
                'color' => $enum ? $workflow->colorHexForStatus($enum) : '#64748B',
            ];
        })->values()->all();
    }

    /**
     * @param  Builder<Shipment>  $shipmentsQ
     */
    protected function buildRecentActivity(Builder $shipmentsQ): array
    {
        $logs = ShipmentLog::query()
            ->whereIn('shipment_id', (clone $shipmentsQ)->select('shipments.id'))
            ->with(['user'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return $logs->map(function (ShipmentLog $log) {
            $title = $log->title;
            if (! $title && $log->status instanceof ShipmentStatus) {
                $title = $log->status->label();
            }
            $title = $title ?: 'Mise à jour';

            return [
                'id' => (string) $log->id,
                'title' => $title,
                'description' => $log->description,
                'date' => $log->created_at?->timezone(config('app.timezone'))->locale('fr')->diffForHumans() ?? '',
                'actor' => $log->user?->name,
                'type' => 'status',
            ];
        })->values()->all();
    }

    protected function scopedPreAlerts($user)
    {
        $q = PreAlert::query()->with('user')->actionableInboundQueue();
        if (! $user->canAccessAllAgencies()) {
            $q->whereHas('user', fn ($u) => $u->where('agency_id', $user->agency_id));
        }

        return $q;
    }

    protected function scopedPickups($user)
    {
        $q = Pickup::query();
        if (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }

        return $q;
    }
}
