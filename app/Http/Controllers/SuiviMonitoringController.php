<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\PickupStatus;
use App\Enums\ShipmentStatus;
use App\Models\AssistedPurchase;
use App\Models\PaymentProof;
use App\Models\Pickup;
use App\Models\Regroupement;
use App\Models\Shipment;
use App\Models\SavTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SuiviMonitoringController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    /**
     * Seuils d'alerte : nombre de jours sans changement de statut.
     */
    private const DELAY_THRESHOLDS = [
        ShipmentStatus::PendingDropOff->value    => 3,
        ShipmentStatus::ReceivedAtHub->value     => 2,
        ShipmentStatus::ReadyForDispatch->value  => 2,
        ShipmentStatus::InTransit->value         => 10,
        ShipmentStatus::CustomsHold->value       => 5,
        ShipmentStatus::ArrivedAtDestination->value => 3,
        ShipmentStatus::DeliveryFailed->value    => 2,
    ];

    /** Tableau de bord principal : alertes + KPIs + tendances */
    public function dashboard(Request $request): JsonResponse
    {
        $user    = $request->user();
        $period  = $request->input('period', 'week');   // day|week|month|quarter|semester|year
        [$start, $prev] = $this->periodBounds($period);

        /* ── Expéditions ── */
        $shipBase = Shipment::query();
        $this->scopeShipmentsForUser($shipBase, $user);
        $shipBase->excludingDrafts();

        $inProgressStatuses = [
            ShipmentStatus::PendingDropOff->value,
            ShipmentStatus::ReceivedAtHub->value,
            ShipmentStatus::ReadyForDispatch->value,
            ShipmentStatus::InTransit->value,
            ShipmentStatus::CustomsHold->value,
            ShipmentStatus::ArrivedAtDestination->value,
            ShipmentStatus::DeliveryFailed->value,
        ];

        $shipmentsInProgress = (clone $shipBase)
            ->whereIn('status', $inProgressStatuses)
            ->count();

        $shipmentsDelivered = (clone $shipBase)
            ->where('status', ShipmentStatus::Delivered->value)
            ->whereBetween('updated_at', [$start, now()])
            ->count();

        $shipmentsDeliveredPrev = (clone $shipBase)
            ->where('status', ShipmentStatus::Delivered->value)
            ->whereBetween('updated_at', [$prev, $start])
            ->count();

        /* ── Retards détectés ── */
        $delayedShipments = $this->getDelayedShipments($shipBase);
        $delayedCount     = count($delayedShipments);

        /* ── Statuts bloqués (aucun mouvement en > seuil) ── */
        $blockedCount = (clone $shipBase)
            ->whereIn('status', $inProgressStatuses)
            ->where('updated_at', '<', now()->subDays(7))
            ->count();

        /* ── Commandes assistées en cours ── */
        $apBase = AssistedPurchase::query();
        if (! $user->canAccessAllAgencies()) {
            $apBase->whereHas('user', fn ($q) => $q->where('agency_id', $user->agency_id));
        }

        $activeOrderStatuses = [
            AssistedPurchaseStatus::PENDING_QUOTE->value,
            AssistedPurchaseStatus::AWAITING_CLIENT_INFO->value,
            AssistedPurchaseStatus::QUOTED->value,
            AssistedPurchaseStatus::AWAITING_PAYMENT->value,
            AssistedPurchaseStatus::PAID->value,
            AssistedPurchaseStatus::ORDERED->value,
        ];

        $ordersInProgress = (clone $apBase)
            ->whereIn('status', $activeOrderStatuses)
            ->count();

        $quotesExpiringSoon = (clone $apBase)
            ->where('status', AssistedPurchaseStatus::QUOTED->value)
            ->where('quote_expires_at', '<=', now()->addDays(2))
            ->where('quote_expires_at', '>', now())
            ->count();

        /* ── SAV ── */
        $openSavTickets = 0;
        $slaAtRisk      = 0;
        if (class_exists(SavTicket::class)) {
            $savBase = SavTicket::query();
            if (! $user->canAccessAllAgencies()) {
                $savBase->where('agency_id', $user->agency_id);
            }
            $openSavTickets = (clone $savBase)
                ->whereNotIn('status', ['resolved', 'closed', 'cancelled'])
                ->count();
            $slaAtRisk = (clone $savBase)
                ->whereNotIn('status', ['resolved', 'closed', 'cancelled'])
                ->where('sla_due_at', '<=', now()->addHours(4))
                ->count();
        }

        /* ── Tendances (livraisons par période) ── */
        $trends = $this->buildTrends($shipBase, $period);

        /* ── Alertes critiques (liste) ── */
        $alerts = $this->buildAlerts($delayedShipments, $quotesExpiringSoon, $slaAtRisk);

        return response()->json([
            'kpis' => [
                'shipments_in_progress'   => $shipmentsInProgress,
                'shipments_delivered'     => $shipmentsDelivered,
                'shipments_delivered_prev'=> $shipmentsDeliveredPrev,
                'delayed_count'           => $delayedCount,
                'blocked_count'           => $blockedCount,
                'orders_in_progress'      => $ordersInProgress,
                'quotes_expiring_soon'    => $quotesExpiringSoon,
                'open_sav_tickets'        => $openSavTickets,
                'sla_at_risk'             => $slaAtRisk,
            ],
            'trends'     => $trends,
            'alerts'     => $alerts,
            'delayed'    => array_slice($delayedShipments, 0, 20),
            'period'     => $period,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** Détail des expéditions en retard */
    public function delayedShipments(Request $request): JsonResponse
    {
        $user     = $request->user();
        $shipBase = Shipment::with(['senderProfile', 'recipientProfile', 'destCountry'])->newQuery();
        $this->scopeShipmentsForUser($shipBase, $user);
        $shipBase->excludingDrafts();

        return response()->json([
            'delayed' => $this->getDelayedShipments($shipBase),
        ]);
    }

    /** Détail des commandes assistées actives avec alertes */
    public function activeOrders(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = AssistedPurchase::with('user')
            ->whereIn('status', [
                AssistedPurchaseStatus::PENDING_QUOTE->value,
                AssistedPurchaseStatus::AWAITING_CLIENT_INFO->value,
                AssistedPurchaseStatus::QUOTED->value,
                AssistedPurchaseStatus::AWAITING_PAYMENT->value,
                AssistedPurchaseStatus::PAID->value,
                AssistedPurchaseStatus::ORDERED->value,
            ]);

        if (! $user->canAccessAllAgencies()) {
            $query->whereHas('user', fn ($q) => $q->where('agency_id', $user->agency_id));
        }

        $orders = $query->orderByDesc('created_at')->limit(50)->get()->map(function ($ap) {
            $ageHours = $ap->created_at->diffInHours(now());
            $isStale  = match ($ap->status->value) {
                AssistedPurchaseStatus::PENDING_QUOTE->value         => $ageHours > 24,
                AssistedPurchaseStatus::AWAITING_CLIENT_INFO->value  => $ageHours > 48,
                AssistedPurchaseStatus::QUOTED->value                => $ageHours > 72,
                default => false,
            };

            return [
                'id'              => $ap->id,
                'reference_code'  => $ap->reference_code ?? 'AP-'.$ap->id,
                'status'          => $ap->status->value,
                'status_label'    => $ap->status_label,
                'age_hours'       => $ageHours,
                'is_stale'        => $isStale,
                'client_name'     => $ap->user?->name,
                'article_label'   => $ap->article_label,
                'quote_expires_at'=> $ap->quote_expires_at?->toIso8601String(),
                'created_at'      => $ap->created_at->toIso8601String(),
                'href'            => '/purchase-orders/'.$ap->id,
            ];
        });

        return response()->json(['orders' => $orders]);
    }

    /**
     * Tableau de suivi unifié : tous les dossiers actifs de tous les modules,
     * chacun enrichi d'une action à faire, de points d'attention et de suggestions.
     */
    public function board(Request $request): JsonResponse
    {
        $user   = $request->user();
        $type   = $request->input('type');       // devis|expedition|sav|ramassage|livraison|regroupement|paiement
        $status = $request->input('status');
        $search = $request->input('search');
        $assignedTo = $request->input('assigned_to');
        $view   = $request->input('view', 'all'); // all|mine|urgences
        $items  = collect();

        // ── Devis (Assisted Purchases) actifs ──
        if (! $type || $type === 'devis') {
            $items = $items->merge($this->boardDevisItems($user, $view));
        }

        // ── Expéditions actives ──
        if (! $type || $type === 'expedition') {
            $items = $items->merge($this->boardExpeditionItems($user, $view));
        }

        // ── SAV ──
        if (! $type || $type === 'sav') {
            $items = $items->merge($this->boardSavItems($user, $view));
        }

        // ── Ramassages ──
        if (! $type || $type === 'ramassage') {
            $items = $items->merge($this->boardPickupItems($user, $view));
        }

        // ── Regroupements ──
        if (! $type || $type === 'regroupement') {
            $items = $items->merge($this->boardRegroupementItems($user));
        }

        // ── Paiements à valider ──
        if (! $type || $type === 'paiement') {
            $items = $items->merge($this->boardPaymentItems($user));
        }

        // ── Filtres globaux ──
        if ($status) {
            $items = $items->filter(fn ($i) => $i['status'] === $status);
        }
        if ($assignedTo) {
            $items = $items->filter(fn ($i) => ($i['assigned_to_id'] ?? null) == $assignedTo);
        }
        if ($search) {
            $q = mb_strtolower($search);
            $items = $items->filter(fn ($i) =>
                str_contains(mb_strtolower($i['reference']), $q) ||
                str_contains(mb_strtolower($i['client_name'] ?? ''), $q)
            );
        }

        // Tri : urgences d'abord, puis dernière activité la plus ancienne
        $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $items = $items->sortBy([
            fn ($a, $b) => ($priorityOrder[$a['priority']] ?? 9) <=> ($priorityOrder[$b['priority']] ?? 9),
            fn ($a, $b) => ($a['last_activity'] ?? '') <=> ($b['last_activity'] ?? ''),
        ])->values();

        // Compteurs
        $counters = [
            'urgences'      => $items->filter(fn ($i) => $i['priority'] === 'critical')->count(),
            'devis'         => $items->filter(fn ($i) => $i['type'] === 'devis')->count(),
            'expeditions'   => $items->filter(fn ($i) => $i['type'] === 'expedition')->count(),
            'ramassages'    => $items->filter(fn ($i) => $i['type'] === 'ramassage')->count(),
            'paiements'     => $items->filter(fn ($i) => $i['type'] === 'paiement')->count(),
            'sav'           => $items->filter(fn ($i) => $i['type'] === 'sav')->count(),
            'regroupements' => $items->filter(fn ($i) => $i['type'] === 'regroupement')->count(),
            'total'         => $items->count(),
        ];

        return response()->json([
            'items'       => $items->take(100)->values(),
            'counters'    => $counters,
            'generated_at'=> now()->toIso8601String(),
        ]);
    }

    /* ─── Board helpers : chaque module ─── */

    private function boardDevisItems($user, string $view): array
    {
        $query = AssistedPurchase::with('user')
            ->whereIn('status', [
                AssistedPurchaseStatus::PENDING_QUOTE->value,
                AssistedPurchaseStatus::AWAITING_CLIENT_INFO->value,
                AssistedPurchaseStatus::QUOTED->value,
                AssistedPurchaseStatus::AWAITING_PAYMENT->value,
                AssistedPurchaseStatus::PAID->value,
                AssistedPurchaseStatus::ORDERED->value,
                AssistedPurchaseStatus::ARRIVED_AT_HUB->value,
            ]);

        if (! $user->canAccessAllAgencies()) {
            $query->whereHas('user', fn ($q) => $q->where('agency_id', $user->agency_id));
        }
        if ($view === 'mine') {
            $query->whereHas('user', fn ($q) => $q->where('agency_id', $user->agency_id));
        }

        return $query->orderByDesc('updated_at')->limit(50)->get()->map(function ($ap) {
            $status = $ap->status instanceof AssistedPurchaseStatus ? $ap->status : AssistedPurchaseStatus::from($ap->status);
            $ageHours = $ap->updated_at->diffInHours(now());

            $action = match ($status) {
                AssistedPurchaseStatus::PENDING_QUOTE        => 'Créer devis',
                AssistedPurchaseStatus::AWAITING_CLIENT_INFO => 'Relancer le client',
                AssistedPurchaseStatus::QUOTED               => 'Envoyer relance',
                AssistedPurchaseStatus::AWAITING_PAYMENT     => 'Vérifier paiement',
                AssistedPurchaseStatus::PAID                 => 'Passer commande fournisseur',
                AssistedPurchaseStatus::ORDERED              => 'Suivre la commande',
                AssistedPurchaseStatus::ARRIVED_AT_HUB       => 'Convertir en expédition',
                default                                      => null,
            };

            $attention = null;
            $priority  = 'medium';
            if ($status === AssistedPurchaseStatus::PENDING_QUOTE && $ageHours > 24) {
                $attention = "En attente de chiffrage depuis {$ageHours}h";
                $priority  = 'high';
            }
            if ($status === AssistedPurchaseStatus::QUOTED && $ap->quote_expires_at) {
                $hoursLeft = now()->diffInHours($ap->quote_expires_at, false);
                if ($hoursLeft <= 0) {
                    $attention = 'Devis expiré';
                    $priority  = 'critical';
                } elseif ($hoursLeft <= 24) {
                    $attention = "Expire dans {$hoursLeft}h";
                    $priority  = 'high';
                }
            }

            $suggestion = null;
            if ($status === AssistedPurchaseStatus::ARRIVED_AT_HUB) {
                $suggestion = 'Regrouper avec d\'autres colis du même client';
            }

            return [
                'id'             => $ap->id,
                'reference'      => $ap->reference_code ?? 'AP-'.$ap->id,
                'type'           => 'devis',
                'type_label'     => 'Devis',
                'client_name'    => $ap->user?->name ?? '—',
                'client_id'      => $ap->user?->id,
                'status'         => $status->value,
                'status_label'   => $status->label(),
                'action'         => $action,
                'attention'      => $attention,
                'suggestion'     => $suggestion,
                'priority'       => $priority,
                'assigned_to'    => null,
                'assigned_to_id' => null,
                'last_activity'  => $ap->updated_at->toIso8601String(),
                'href'           => '/purchase-orders/'.$ap->id.'/chiffrage',
            ];
        })->toArray();
    }

    private function boardExpeditionItems($user, string $view): array
    {
        $query = Shipment::with(['senderProfile', 'destCountry'])
            ->whereIn('status', [
                ShipmentStatus::PendingDropOff->value,
                ShipmentStatus::ReceivedAtHub->value,
                ShipmentStatus::ReadyForDispatch->value,
                ShipmentStatus::InTransit->value,
                ShipmentStatus::CustomsHold->value,
                ShipmentStatus::ArrivedAtDestination->value,
                ShipmentStatus::DeliveryFailed->value,
            ]);

        $this->scopeShipmentsForUser($query, $user);
        $query->excludingDrafts();

        if ($view === 'mine') {
            $query->where(function ($q) use ($user) {
                $q->where('creator_user_id', $user->id)
                  ->orWhere('assigned_driver_id', $user->id);
            });
        }

        return $query->orderByDesc('updated_at')->limit(50)->get()->map(function ($s) {
            $status    = $s->status instanceof ShipmentStatus ? $s->status : ShipmentStatus::from($s->status);
            $daysInStatus = (int) $s->updated_at->diffInDays(now());
            $threshold = self::DELAY_THRESHOLDS[$status->value] ?? null;

            $action = match ($status) {
                ShipmentStatus::PendingDropOff       => 'Réceptionner au hub',
                ShipmentStatus::ReceivedAtHub        => 'Préparer pour expédition',
                ShipmentStatus::ReadyForDispatch      => 'Expédier',
                ShipmentStatus::InTransit            => 'Suivre le transit',
                ShipmentStatus::CustomsHold          => 'Contacter transitaire',
                ShipmentStatus::ArrivedAtDestination => 'Planifier la livraison',
                ShipmentStatus::DeliveryFailed       => 'Replanifier la livraison',
                default                              => null,
            };

            $attention = null;
            $priority  = 'medium';
            if ($threshold && $daysInStatus >= $threshold) {
                $attention = "Bloqué en « {$status->label()} » depuis {$daysInStatus}j";
                $priority  = $daysInStatus >= $threshold * 2 ? 'critical' : 'high';
            }
            if ($status === ShipmentStatus::CustomsHold) {
                $attention = "En douane depuis {$daysInStatus}j";
                $priority  = $daysInStatus >= 5 ? 'critical' : 'high';
            }
            if ($status === ShipmentStatus::DeliveryFailed) {
                $priority = 'high';
                $attention = 'Livraison échouée — à replanifier';
            }

            $suggestion = null;
            if ($status === ShipmentStatus::CustomsHold && $daysInStatus > 3) {
                $suggestion = 'Documents manquants — demander au client';
            }

            return [
                'id'             => $s->id,
                'reference'      => $s->public_tracking ?? $s->tracking_number,
                'type'           => 'expedition',
                'type_label'     => 'Expédition',
                'client_name'    => $s->senderProfile?->full_name ?? '—',
                'client_id'      => $s->creator_user_id,
                'status'         => $status->value,
                'status_label'   => $status->label(),
                'action'         => $action,
                'attention'      => $attention,
                'suggestion'     => $suggestion,
                'priority'       => $priority,
                'assigned_to'    => null,
                'assigned_to_id' => $s->assigned_driver_id,
                'last_activity'  => $s->updated_at->toIso8601String(),
                'href'           => '/shipments/'.$s->id,
            ];
        })->toArray();
    }

    private function boardSavItems($user, string $view): array
    {
        if (! class_exists(SavTicket::class)) {
            return [];
        }

        $query = SavTicket::with(['client', 'assignee'])
            ->whereNotIn('status', ['resolved', 'closed', 'cancelled']);

        if (! $user->canAccessAllAgencies()) {
            $query->where('agency_id', $user->agency_id);
        }
        if ($view === 'mine') {
            $query->where('assigned_to', $user->id);
        }

        return $query->orderByDesc('updated_at')->limit(50)->get()->map(function ($t) {
            $priorityMap = ['urgent' => 'critical', 'normal' => 'high', 'low' => 'medium'];
            $priority    = $priorityMap[$t->priority?->value ?? $t->priority ?? 'normal'] ?? 'medium';

            $action = match ($t->status?->value ?? $t->status) {
                'open'           => 'S\'assigner et répondre',
                'in_progress'    => 'Répondre au client',
                'waiting_client' => 'Attente réponse client',
                'escalated'      => 'Traiter l\'escalade',
                default          => null,
            };

            $attention = null;
            if ($t->sla_deadline_at) {
                $hoursLeft = now()->diffInHours($t->sla_deadline_at, false);
                if ($hoursLeft <= 0) {
                    $attention = 'SLA dépassé';
                    $priority  = 'critical';
                } elseif ($hoursLeft <= 2) {
                    $attention = "SLA dans {$hoursLeft}h";
                    $priority  = 'critical';
                }
            }

            $suggestion = null;
            $category = $t->category?->value ?? $t->category;
            if (in_array($category, ['lost_damaged', 'LOST_DAMAGED'])) {
                $suggestion = 'Créer remboursement assurance';
            }

            return [
                'id'             => $t->id,
                'reference'      => $t->reference_code,
                'type'           => 'sav',
                'type_label'     => 'SAV',
                'client_name'    => $t->client?->name ?? '—',
                'client_id'      => $t->client_id,
                'status'         => $t->status?->value ?? $t->status,
                'status_label'   => $t->status?->label() ?? $t->status,
                'action'         => $action,
                'attention'      => $attention,
                'suggestion'     => $suggestion,
                'priority'       => $priority,
                'assigned_to'    => $t->assignee?->name,
                'assigned_to_id' => $t->assigned_to,
                'last_activity'  => $t->updated_at->toIso8601String(),
                'href'           => '/sav/'.$t->uuid,
            ];
        })->toArray();
    }

    private function boardPickupItems($user, string $view): array
    {
        $activeStatuses = [
            PickupStatus::Draft->value,
            PickupStatus::DriverAssigned->value,
            PickupStatus::Accepted->value,
            PickupStatus::EnRoute->value,
        ];

        $query = Pickup::with(['client', 'driver'])
            ->whereIn('status', $activeStatuses);

        $this->scopeByAgency($query, $user);
        if ($view === 'mine') {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('assigned_driver_id', $user->id);
            });
        }

        return $query->orderByDesc('updated_at')->limit(30)->get()->map(function ($p) {
            $status = $p->status instanceof PickupStatus ? $p->status : PickupStatus::from($p->status);

            $action = match ($status) {
                PickupStatus::Draft          => 'Assigner chauffeur',
                PickupStatus::DriverAssigned => 'En attente d\'acceptation',
                PickupStatus::Accepted       => 'Démarrer le ramassage',
                PickupStatus::EnRoute        => 'Confirmer la collecte',
                default                      => null,
            };

            $priority = $status === PickupStatus::Draft ? 'high' : 'medium';

            return [
                'id'             => $p->id,
                'reference'      => 'RAM-'.str_pad($p->id, 4, '0', STR_PAD_LEFT),
                'type'           => 'ramassage',
                'type_label'     => 'Ramassage',
                'client_name'    => $p->client?->name ?? '—',
                'client_id'      => $p->user_id,
                'status'         => $status->value,
                'status_label'   => $status->label(),
                'action'         => $action,
                'attention'      => null,
                'suggestion'     => null,
                'priority'       => $priority,
                'assigned_to'    => $p->driver?->name,
                'assigned_to_id' => $p->assigned_driver_id,
                'last_activity'  => $p->updated_at->toIso8601String(),
                'href'           => '/pickups',
            ];
        })->toArray();
    }

    private function boardRegroupementItems($user): array
    {
        $query = Regroupement::withCount('shipments')
            ->whereNotIn('status', [
                ShipmentStatus::Delivered->value,
                ShipmentStatus::Cancelled->value,
            ]);

        $this->scopeByAgency($query, $user);

        return $query->orderByDesc('updated_at')->limit(20)->get()->map(function ($r) {
            $status = $r->status instanceof ShipmentStatus ? $r->status : ShipmentStatus::from($r->status);

            $action = match ($status) {
                ShipmentStatus::ReceivedAtHub    => 'Compléter le groupe',
                ShipmentStatus::ReadyForDispatch => 'Expédier le lot',
                ShipmentStatus::InTransit        => 'Suivre le transit',
                default                          => 'Vérifier le groupe',
            };

            return [
                'id'             => $r->id,
                'reference'      => $r->batch_number,
                'type'           => 'regroupement',
                'type_label'     => 'Regroupement',
                'client_name'    => null,
                'client_id'      => null,
                'status'         => $status->value,
                'status_label'   => $status->label(),
                'action'         => $action,
                'attention'      => null,
                'suggestion'     => $r->shipments_count < 3 ? 'Ajouter des colis pour optimiser' : null,
                'priority'       => 'low',
                'assigned_to'    => null,
                'assigned_to_id' => null,
                'last_activity'  => $r->updated_at->toIso8601String(),
                'href'           => '/regroupements',
            ];
        })->toArray();
    }

    private function boardPaymentItems($user): array
    {
        if (! class_exists(PaymentProof::class)) {
            return [];
        }

        $query = PaymentProof::query()
            ->with(['invoice.user'])
            ->where('status', 'pending')
            ->whereHas('invoice', function ($q) use ($user) {
                if (! $user->canAccessAllAgencies()) {
                    $q->where('agency_id', $user->agency_id);
                }
            });

        return $query->orderByDesc('created_at')->limit(20)->get()->map(function ($pp) {
            $hoursWaiting = $pp->created_at->diffInHours(now());
            $clientUser    = $pp->invoice?->user;

            return [
                'id'             => $pp->id,
                'reference'      => 'PAY-'.str_pad((string) $pp->id, 4, '0', STR_PAD_LEFT),
                'type'           => 'paiement',
                'type_label'     => 'Paiement',
                'client_name'    => $clientUser?->name ?? '—',
                'client_id'      => $clientUser?->id,
                'status'         => 'pending',
                'status_label'   => 'À valider',
                'action'         => 'Valider paiement',
                'attention'      => $hoursWaiting > 48 ? "En attente depuis {$hoursWaiting}h" : null,
                'suggestion'     => null,
                'priority'       => $hoursWaiting > 48 ? 'high' : 'medium',
                'assigned_to'    => null,
                'assigned_to_id' => null,
                'last_activity'  => $pp->created_at->toIso8601String(),
                'href'           => '/finance/payment-proofs',
            ];
        })->toArray();
    }

    /* ─── Helpers privés ─── */

    private function getDelayedShipments(mixed $baseQuery): array
    {
        $now     = now();
        $delayed = [];

        foreach (self::DELAY_THRESHOLDS as $status => $maxDays) {
            $rows = (clone $baseQuery)
                ->where('status', $status)
                ->where('updated_at', '<', $now->copy()->subDays($maxDays))
                ->with(['senderProfile', 'destCountry'])
                ->orderBy('updated_at')
                ->limit(100)
                ->get();

            foreach ($rows as $s) {
                $daysStuck = (int) $s->updated_at->diffInDays($now);
                $delayed[] = [
                    'id'            => $s->id,
                    'uuid'          => $s->uuid,
                    'public_tracking'=> $s->public_tracking,
                    'status'        => $status,
                    'status_label'  => ShipmentStatus::from($status)->label(),
                    'days_stuck'    => $daysStuck,
                    'threshold'     => $maxDays,
                    'client_name'   => $s->senderProfile?->full_name ?? '—',
                    'destination'   => $s->destCountry?->name ?? '—',
                    'updated_at'    => $s->updated_at->toIso8601String(),
                    'href'          => '/shipments/'.$s->id,
                ];
            }
        }

        usort($delayed, fn ($a, $b) => $b['days_stuck'] <=> $a['days_stuck']);

        return $delayed;
    }

    private function buildTrends(mixed $baseQuery, string $period): array
    {
        $format = match ($period) {
            'day'      => '%H:00',
            'week'     => '%Y-%m-%d',
            'month'    => '%Y-%m-%d',
            'quarter'  => '%Y-%u',
            'semester' => '%Y-%m',
            'year'     => '%Y-%m',
            default    => '%Y-%m-%d',
        };

        [$start] = $this->periodBounds($period);

        $rows = (clone $baseQuery)
            ->selectRaw("DATE_FORMAT(created_at, ?) as label, COUNT(*) as total, SUM(status = ?) as delivered, SUM(status IN (?,?,?,?,?,?,?)) as in_progress",
                [
                    $format,
                    ShipmentStatus::Delivered->value,
                    ShipmentStatus::PendingDropOff->value,
                    ShipmentStatus::ReceivedAtHub->value,
                    ShipmentStatus::ReadyForDispatch->value,
                    ShipmentStatus::InTransit->value,
                    ShipmentStatus::CustomsHold->value,
                    ShipmentStatus::ArrivedAtDestination->value,
                    ShipmentStatus::DeliveryFailed->value,
                ]
            )
            ->where('shipments.created_at', '>=', $start)
            ->groupByRaw("DATE_FORMAT(created_at, ?)", [$format])
            ->orderByRaw("DATE_FORMAT(created_at, ?)", [$format])
            ->get();

        return $rows->map(fn ($r) => [
            'label'       => $r->label,
            'total'       => (int) $r->total,
            'delivered'   => (int) $r->delivered,
            'in_progress' => (int) $r->in_progress,
        ])->values()->toArray();
    }

    private function buildAlerts(array $delayed, int $quotesExpiring, int $slaAtRisk): array
    {
        $alerts = [];

        foreach (array_slice($delayed, 0, 5) as $d) {
            $alerts[] = [
                'type'     => 'delay',
                'severity' => $d['days_stuck'] >= $d['threshold'] * 2 ? 'critical' : 'warning',
                'message'  => "Expédition {$d['public_tracking']} bloquée depuis {$d['days_stuck']} j en « {$d['status_label']} »",
                'href'     => $d['href'],
            ];
        }

        if ($quotesExpiring > 0) {
            $alerts[] = [
                'type'     => 'quote_expiry',
                'severity' => 'warning',
                'message'  => "{$quotesExpiring} devis expire(nt) dans moins de 48 h",
                'href'     => '/monitoring',
            ];
        }

        if ($slaAtRisk > 0) {
            $alerts[] = [
                'type'     => 'sla',
                'severity' => 'critical',
                'message'  => "{$slaAtRisk} ticket(s) SAV risquent de dépasser leur SLA",
                'href'     => '/sav',
            ];
        }

        return $alerts;
    }

    /** Retourne [start, previousStart] selon la période. */
    private function periodBounds(string $period): array
    {
        $start = match ($period) {
            'day'      => now()->startOfDay(),
            'week'     => now()->startOfWeek(),
            'month'    => now()->startOfMonth(),
            'quarter'  => now()->startOfQuarter(),
            'semester' => now()->startOfYear()->month <= 6 ? now()->startOfYear() : now()->startOfYear()->addMonths(6),
            'year'     => now()->startOfYear(),
            default    => now()->startOfWeek(),
        };

        $length = match ($period) {
            'day'      => 1,
            'week'     => 7,
            'month'    => 30,
            'quarter'  => 90,
            'semester' => 180,
            'year'     => 365,
            default    => 7,
        };

        $prev = $start->copy()->subDays($length);

        return [$start, $prev];
    }
}
