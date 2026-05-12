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
use App\Models\SavTicket;
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

        $savBase = SavTicket::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id));
        $savOpenCount = (clone $savBase)->whereIn('status', ['open', 'in_progress', 'waiting_client', 'escalated'])->count();
        $savResolvedToday = (clone $savBase)->whereDate('resolved_at', today())->count();
        $savEscalated = (clone $savBase)->where('status', 'escalated')->count();
        $savSlaAtRisk = (clone $savBase)
            ->whereNotNull('sla_deadline_at')
            ->whereIn('status', ['open', 'in_progress'])
            ->where('sla_deadline_at', '<=', now()->addHours(2))
            ->count();

        $shipmentsInTransit = (clone $shipmentsStatsQ)->where('status', ShipmentStatus::InTransit)->count();

        $assistedBase = AssistedPurchase::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id));
        $assistedToday = (clone $assistedBase)->whereDate('created_at', today())->count();
        $assistedQuotesPending = (clone $assistedBase)->where('status', 'pending')->count();

        $stats = [
            'shipments_total' => (clone $shipmentsStatsQ)->count(),
            'shipments_in_transit' => $shipmentsInTransit,
            'pre_alerts' => $preAlertsPending,
            'pre_alerts_pending' => $preAlertsPending,
            'pickups_count' => $pickupsToday,
            'pickups_today' => $pickupsToday,
            'payment_proofs_pending' => $paymentProofsPending,
            'monthly_revenue' => round($monthlyRevenue, 2),
            'clients_count' => $clientsCount,
            'shipments_trend' => $this->trendPercent((float) $shipmentsThisMonth, (float) $shipmentsLastMonth),
            'revenue_trend' => $this->trendPercent($monthlyRevenue, $monthlyRevenueLast),
            'sav_open' => $savOpenCount,
            'sav_resolved_today' => $savResolvedToday,
            'sav_escalated' => $savEscalated,
            'sav_sla_at_risk' => $savSlaAtRisk,
            'assisted_today' => $assistedToday,
            'assisted_quotes_pending' => $assistedQuotesPending,
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

        $payload['urgent_actions'] = $this->buildUrgentActions($user, $savBase);
        $payload['pending_dossiers'] = $this->buildPendingDossiers($user);
        $payload['today_activity'] = $this->buildTodayActivity($user);
        $payload['system_alerts'] = $this->buildSystemAlerts($user);
        $payload['handover'] = $this->buildHandoverSection($user);

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

    protected function buildUrgentActions(User $user, $savBase): array
    {
        $actions = [];

        $urgentTickets = (clone $savBase)
            ->whereIn('status', ['open', 'in_progress'])
            ->where('priority', 'urgent')
            ->orderBy('sla_deadline_at')
            ->limit(5)
            ->get();

        foreach ($urgentTickets as $t) {
            $actions[] = [
                'type' => 'sav_urgent',
                'label' => "{$t->reference_code} · {$t->subject}",
                'detail' => $t->client?->name ?? 'Client inconnu',
                'href' => "/sav/{$t->uuid}",
                'sla_remaining' => $t->sla_remaining_minutes,
            ];
        }

        $pendingProofs = PaymentProof::query()
            ->where('status', 'pending')
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->whereHas('invoice.shipment', fn ($s) => $s->where('agency_id', $user->agency_id)))
            ->latest()
            ->limit(3)
            ->with('invoice')
            ->get();

        foreach ($pendingProofs as $pp) {
            $actions[] = [
                'type' => 'payment_proof',
                'label' => "Paiement à valider · {$pp->amount} {$pp->invoice?->currency}",
                'detail' => $pp->created_at?->diffForHumans(),
                'href' => '/finance/payment-proofs',
            ];
        }

        return $actions;
    }

    protected function buildPendingDossiers(User $user): array
    {
        $dossiers = [];

        $quotesToSend = AssistedPurchase::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->where('status', 'pending')
            ->with('user')
            ->orderBy('created_at')
            ->limit(5)
            ->get();

        foreach ($quotesToSend as $ap) {
            $dossiers[] = [
                'type' => 'quote_to_send',
                'section' => 'Devis à envoyer',
                'label' => $ap->reference_code ?? "AA-{$ap->id}",
                'detail' => ($ap->user?->name ?? 'Client') . ' · Reçu ' . ($ap->created_at?->diffForHumans() ?? ''),
                'href' => "/purchase-orders/{$ap->id}/chiffrage",
                'action_label' => 'Créer devis',
            ];
        }

        $hubPackages = PreAlert::query()
            ->actionableInboundQueue()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('agency_id', $user->agency_id)))
            ->with('user')
            ->orderBy('created_at')
            ->limit(5)
            ->get();

        foreach ($hubPackages as $pa) {
            $dossiers[] = [
                'type' => 'hub_reception',
                'section' => 'Colis à réceptionner au hub',
                'label' => $pa->reference_code ?? "PA-{$pa->id}",
                'detail' => ($pa->user?->name ?? '') . ' · ' . ($pa->created_at?->diffForHumans() ?? ''),
                'href' => "/shipment-notices",
                'action_label' => 'Réceptionner',
            ];
        }

        $toConvert = AssistedPurchase::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->where('status', 'at_hub')
            ->with('user')
            ->orderBy('updated_at')
            ->limit(5)
            ->get();

        foreach ($toConvert as $ap) {
            $days = $ap->updated_at ? (int) now()->diffInDays($ap->updated_at) : 0;
            $dossiers[] = [
                'type' => 'convert_shipment',
                'section' => 'Conversions en expédition',
                'label' => $ap->reference_code ?? "AA-{$ap->id}",
                'detail' => "AT_HUB depuis {$days}j",
                'href' => "/purchase-orders/{$ap->id}",
                'action_label' => 'Convertir',
            ];
        }

        return $dossiers;
    }

    protected function buildTodayActivity(User $user): array
    {
        $thisMonth = now()->startOfMonth();

        $shipmentsQ = Shipment::query();
        $this->scopeShipmentsForUser($shipmentsQ, $user);
        $shipmentsQ->excludingDrafts();

        $assistedBase = AssistedPurchase::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id));

        $savBase = SavTicket::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id));

        $invBase = $this->scopedInvoices($user);

        return [
            'expeditions' => [
                'created_today' => (clone $shipmentsQ)->whereDate('created_at', today())->count(),
                'in_transit' => (clone $shipmentsQ)->where('status', ShipmentStatus::InTransit)->count(),
                'arrived' => (clone $shipmentsQ)->where('status', ShipmentStatus::ArrivedAtDestination)->whereDate('updated_at', today())->count(),
                'delivered' => (clone $shipmentsQ)->where('status', ShipmentStatus::Delivered)->whereDate('updated_at', today())->count(),
            ],
            'achat_assiste' => [
                'received_today' => (clone $assistedBase)->whereDate('created_at', today())->count(),
                'quotes_sent' => (clone $assistedBase)->whereIn('status', ['quote_sent', 'accepted', 'paid', 'ordered', 'at_hub', 'converted'])->where('created_at', '>=', $thisMonth)->count(),
                'accepted_today' => (clone $assistedBase)->where('status', 'accepted')->whereDate('updated_at', today())->count(),
                'pending_payment' => (clone $assistedBase)->where('status', 'accepted')->count(),
            ],
            'sav' => [
                'open' => (clone $savBase)->whereIn('status', ['open', 'in_progress', 'waiting_client', 'escalated'])->count(),
                'resolved_today' => (clone $savBase)->whereDate('resolved_at', today())->count(),
                'sla_rate' => $this->computeSlaRate($savBase, $thisMonth),
                'escalated' => (clone $savBase)->where('status', 'escalated')->count(),
            ],
            'finance' => [
                'revenue_today' => round((float) (clone $invBase)->where('status', 'paid')->whereDate('paid_at', today())->sum('amount'), 2),
                'payments_received' => (clone $invBase)->where('status', 'paid')->whereDate('paid_at', today())->count(),
                'refunds' => 0,
                'pending_validation' => PaymentProof::query()->where('status', 'pending')
                    ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->whereHas('invoice.shipment', fn ($s) => $s->where('agency_id', $user->agency_id)))->count(),
            ],
        ];
    }

    protected function computeSlaRate($savBase, $thisMonth): int
    {
        $slaRespected = (clone $savBase)
            ->where('created_at', '>=', $thisMonth)
            ->whereNotNull('first_response_at')
            ->whereNotNull('sla_deadline_at')
            ->whereRaw('first_response_at <= sla_deadline_at')
            ->count();
        $slaTotal = (clone $savBase)
            ->where('created_at', '>=', $thisMonth)
            ->whereNotNull('first_response_at')
            ->count();

        return $slaTotal > 0 ? (int) round(100 * $slaRespected / $slaTotal) : 0;
    }

    protected function buildSystemAlerts(User $user): array
    {
        $alerts = [];

        $overdueShipments = Shipment::query();
        $this->scopeShipmentsForUser($overdueShipments, $user);
        $overdueCount = (clone $overdueShipments)
            ->where('status', ShipmentStatus::InTransit)
            ->where('created_at', '<', now()->subDays(14))
            ->count();

        if ($overdueCount > 0) {
            $alerts[] = [
                'level' => 'warning',
                'message' => "{$overdueCount} expédition(s) en transit depuis plus de 14 jours",
                'href' => '/analytics/overdue',
            ];
        }

        $escalatedTickets = SavTicket::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->where('status', 'escalated')
            ->count();

        if ($escalatedTickets > 0) {
            $alerts[] = [
                'level' => 'warning',
                'message' => "{$escalatedTickets} ticket(s) SAV escaladé(s) en attente",
                'href' => '/sav?status=escalated',
            ];
        }

        $overdueInvoices = Invoice::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->whereNotIn('status', ['paid', 'cancelled', 'draft'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();

        if ($overdueInvoices > 0) {
            $alerts[] = [
                'level' => 'warning',
                'message' => "{$overdueInvoices} facture(s) en retard de paiement",
                'href' => '/finance/invoices?status=overdue',
            ];
        }

        return $alerts;
    }

    protected function buildHandoverSection(User $user): array
    {
        $colleagues = User::query()
            ->where('id', '!=', $user->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['operator', 'agency_admin']))
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->withCount(['savTicketsAssigned as open_tickets_count' => fn ($q) => $q->whereIn('status', ['open', 'in_progress', 'waiting_client'])])
            ->having('open_tickets_count', '>', 0)
            ->limit(5)
            ->get();

        return $colleagues->map(fn ($c) => [
            'user_name' => $c->name,
            'open_count' => $c->open_tickets_count,
            'dossiers' => SavTicket::where('assigned_to', $c->id)
                ->whereIn('status', ['open', 'in_progress', 'waiting_client'])
                ->orderByDesc('updated_at')
                ->limit(3)
                ->get()
                ->map(fn ($t) => [
                    'reference' => $t->reference_code,
                    'subject' => $t->subject,
                    'status' => $t->status_label,
                    'last_active' => $t->updated_at?->diffForHumans(),
                    'href' => "/sav/{$t->uuid}",
                ]),
        ])->all();
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
