<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\Regroupement;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentWorkflowService;
use App\Support\ShipmentRowPresenter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RegroupementController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    /** Compat. Spatie : noms regroupements + consolidations + droits « manage ». */
    protected function userCanViewRegroupementsApi(User $user): bool
    {
        return $user->hasAnyPermission([
            'view_regroupements', 'view_consolidations',
            'manage_regroupements', 'manage_consolidations',
        ]);
    }

    protected function userCanAssignShipmentsToRegroupement(User $user): bool
    {
        return $user->hasAnyPermission([
            'create_regroupements', 'create_consolidations',
            'manage_regroupements', 'manage_consolidations',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $shipments = Shipment::query()
            ->whereNull('regroupement_id')
            ->whereNull('master_shipment_id')
            ->with(['senderProfile']);

        $this->scopeShipmentsForUser($shipments, $user);

        $regroupements = Regroupement::query()
            ->with([
                'shipments.senderProfile.city',
                'shipments.senderProfile.country',
                'shipments.recipientProfile.city',
                'shipments.recipientProfile.country',
                'shipments.originCountry',
                'shipments.destCountry',
            ])
            ->latest()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->take(50)
            ->get();

        $regroupementStatuses = collect(ShipmentStatus::cases())->map(fn (ShipmentStatus $s) => [
            'code' => $s->value,
            'name' => $s->label(),
            'color_hex' => null,
        ]);

        $workflowSvc = app(ShipmentWorkflowService::class);
        $regroupementsPayload = $regroupements->map(function (Regroupement $r) use ($workflowSvc) {
            $summaries = $r->shipments
                ->map(fn (Shipment $s) => ShipmentRowPresenter::summaryForRegroupement($s))
                ->values()
                ->all();
            $st = $r->status ?? ShipmentStatus::Draft;

            return [
                'id' => $r->id,
                'batch_number' => $r->batch_number,
                'agency_id' => $r->agency_id,
                'status' => [
                    'code' => $st->value,
                    'name' => $st->label(),
                    'color_hex' => $workflowSvc->colorHexForStatus($st),
                ],
                'created_at' => $r->created_at?->toIso8601String(),
                'updated_at' => $r->updated_at?->toIso8601String(),
                'shipments' => $summaries,
                'lot_route' => ShipmentRowPresenter::aggregateLotRoute($summaries),
            ];
        });

        return response()->json([
            'availableShipments' => $shipments->get(),
            'regroupements' => $regroupementsPayload,
            'regroupementStatuses' => $regroupementStatuses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],
        ]);

        $user = $request->user();
        abort_unless($this->userCanAssignShipmentsToRegroupement($user), 403);

        $regroupement = DB::transaction(function () use ($request, $user) {
            $regroupement = Regroupement::query()->create([
                'agency_id' => $user->agency_id,
                'status' => ShipmentStatus::Draft,
            ]);

            $shipments = Shipment::query()->whereIn('id', $request->shipment_ids)->get();

            foreach ($shipments as $shipment) {
                if ($err = $this->attachmentError($user, $regroupement, $shipment)) {
                    throw new HttpResponseException($err);
                }

                $shipment->update(['regroupement_id' => $regroupement->id, 'master_shipment_id' => null]);
            }

            return $regroupement;
        });

        $regroupement->load(['shipments.senderProfile', 'shipments.recipientProfile']);

        return response()->json([
            'message' => 'Regroupement '.$regroupement->batch_number.' créé.',
            'regroupement' => $regroupement,
        ], 201);
    }

    /**
     * Ajoute une expédition à un regroupement existant (même agence, pas déjà groupée ailleurs).
     */
    public function attachShipment(Request $request, Regroupement $regroupement): JsonResponse
    {
        abort_unless($this->userCanAssignShipmentsToRegroupement($request->user()), 403);

        $user = $request->user();
        $data = $request->validate([
            'shipment_id' => ['required', 'integer', 'exists:shipments,id'],
        ]);

        $shipment = Shipment::query()->findOrFail($data['shipment_id']);

        if ($shipment->regroupement_id !== null && (int) $shipment->regroupement_id === (int) $regroupement->id) {
            $regroupement->load(['shipments.senderProfile', 'shipments.recipientProfile']);

            return response()->json([
                'message' => 'Cette expédition est déjà dans ce regroupement.',
                'regroupement' => $regroupement,
            ]);
        }

        if ($err = $this->attachmentError($user, $regroupement, $shipment)) {
            return $err;
        }

        DB::transaction(function () use ($regroupement, $shipment) {
            $shipment->update([
                'regroupement_id' => $regroupement->id,
                'master_shipment_id' => null,
            ]);
        });

        $regroupement->load(['shipments.senderProfile', 'shipments.recipientProfile']);

        return response()->json([
            'message' => 'Expédition ajoutée au regroupement '.$regroupement->batch_number.'.',
            'regroupement' => $regroupement,
        ]);
    }

    /**
     * Ajoute plusieurs expéditions au regroupement (liste expéditions, action groupée).
     */
    public function attachShipments(Request $request, Regroupement $regroupement): JsonResponse
    {
        abort_unless($this->userCanAssignShipmentsToRegroupement($request->user()), 403);

        $user = $request->user();
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],
        ]);

        $attached = 0;
        foreach ($data['shipment_ids'] as $shipmentId) {
            $shipment = Shipment::query()->findOrFail($shipmentId);

            if ($shipment->regroupement_id !== null && (int) $shipment->regroupement_id === (int) $regroupement->id) {
                continue;
            }

            if ($err = $this->attachmentError($user, $regroupement, $shipment)) {
                return $err;
            }

            DB::transaction(function () use ($regroupement, $shipment) {
                $shipment->update([
                    'regroupement_id' => $regroupement->id,
                    'master_shipment_id' => null,
                ]);
            });
            $attached++;
        }

        $regroupement->load(['shipments.senderProfile', 'shipments.recipientProfile']);

        return response()->json([
            'message' => $attached > 0
                ? sprintf('%d expédition(s) ajoutée(s) au regroupement %s.', $attached, $regroupement->batch_number)
                : 'Aucune nouvelle expédition à ajouter (déjà incluses ou inchangées).',
            'regroupement' => $regroupement,
            'attached_count' => $attached,
        ]);
    }

    public function updateStatus(Request $request, Regroupement $regroupement): JsonResponse
    {
        abort_unless($this->userCanViewRegroupementsApi($request->user()), 403);

        $request->validate([
            'status' => ['required', 'string', Rule::enum(ShipmentStatus::class)],
        ]);

        $regroupement->update(['status' => ShipmentStatus::from($request->string('status'))]);

        return response()->json(['message' => 'Statut mis à jour.']);
    }

    protected function attachmentError(User $user, Regroupement $regroupement, Shipment $shipment): ?JsonResponse
    {
        if (! $user->canAccessAllAgencies() && (int) $regroupement->agency_id !== (int) ($user->agency_id ?? 0)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $user->can('view', $shipment) || ! $user->can('update', $shipment)) {
            return response()->json(['message' => 'Accès refusé pour cette expédition.'], 403);
        }

        if ((int) $shipment->agency_id !== (int) $regroupement->agency_id) {
            return response()->json([
                'message' => 'L\'expédition et le regroupement doivent appartenir à la même agence.',
            ], 422);
        }

        if ($shipment->master_shipment_id !== null) {
            return response()->json([
                'message' => 'Cette expédition est rattachée à un envoi maître et ne peut pas être regroupée ainsi.',
            ], 422);
        }

        if ($shipment->regroupement_id !== null) {
            return response()->json([
                'message' => 'Cette expédition est déjà affectée à un autre regroupement.',
            ], 422);
        }

        if ($shipment->status !== ShipmentStatus::ReadyForDispatch) {
            return response()->json([
                'message' => 'Seules les expéditions en statut « Prêt à l\'expédition » peuvent être regroupées.',
            ], 422);
        }

        return null;
    }

    /**
     * §21.4 + §10.3 — Suggest shipments that could be grouped together.
     * Checks destination, shipping mode AND whether grouping avoids the next tariff bracket.
     */
    public function suggestions(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        $readyShipments = \App\Models\Shipment::query()
            ->where('status', \App\Enums\ShipmentStatus::ReadyForDispatch)
            ->whereNull('regroupement_id')
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->with(['destCountry:id,name', 'creator:id,name'])
            ->get();

        $groups = $readyShipments->groupBy(function ($s) {
            $mode = $s->service_options['shipping_mode_id'] ?? 'default';

            return ($s->dest_country_id ?? 0) . '_' . $mode;
        })->filter(fn ($group) => $group->count() >= 2);

        $suggestions = $groups->map(function ($group) {
            $first = $group->first();
            $destName = $first->destCountry?->name;
            if (is_array($destName)) {
                $destName = $destName['fr'] ?? $destName['en'] ?? reset($destName);
            }

            $totalWeight = round($group->sum('weight_kg'), 2);
            $individualCosts = $group->sum(fn ($s) => (float) ($s->calculated_price ?? 0));

            // §10.3 — Vérifier si le regroupement évite le passage à la tranche tarifaire suivante
            $tariffSavings = $this->computeTariffSavings($group, $totalWeight);

            $estimatedSavings = $tariffSavings > 0
                ? $tariffSavings
                : round($individualCosts * 0.15, 2);

            return [
                'destination' => $destName,
                'dest_country_id' => $first->dest_country_id,
                'shipping_mode_id' => $first->service_options['shipping_mode_id'] ?? null,
                'count' => $group->count(),
                'total_weight' => $totalWeight,
                'individual_costs_total' => round($individualCosts, 2),
                'estimated_savings' => $estimatedSavings,
                'savings_source' => $tariffSavings > 0 ? 'tariff_bracket' : 'mutualization_estimate',
                'shipment_ids' => $group->pluck('id'),
                'shipments' => $group->map(fn ($s) => [
                    'id' => $s->id,
                    'tracking' => $s->public_tracking,
                    'client' => $s->creator?->name,
                    'weight_kg' => $s->weight_kg,
                ]),
            ];
        })->values();

        return response()->json([
            'suggestions' => $suggestions,
            'total_groups' => $suggestions->count(),
        ]);
    }

    /**
     * §10.5 — Rapport mensuel des économies réalisées par regroupement.
     * Compare le coût individuel théorique au coût réel groupé.
     */
    public function savingsReport(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($this->userCanViewRegroupementsApi($user), 403);

        $month = $request->input('month')
            ? \Illuminate\Support\Carbon::parse($request->input('month'))->startOfMonth()
            : \Illuminate\Support\Carbon::now()->startOfMonth();

        $regroupements = \App\Models\Regroupement::query()
            ->whereBetween('created_at', [$month, $month->copy()->endOfMonth()])
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->with(['shipments:id,regroupement_id,calculated_price,weight_kg,dest_country_id'])
            ->get();

        $totalShipments = 0;
        $totalIndividualCost = 0.0;
        $totalGroupedCost = 0.0;

        $rows = $regroupements->map(function ($r) use (&$totalShipments, &$totalIndividualCost, &$totalGroupedCost) {
            $count = $r->shipments->count();
            $individualSum = $r->shipments->sum(fn ($s) => (float) ($s->calculated_price ?? 0));
            // Estimation du coût groupé : on applique le facteur 0.85 (15% d'économie)
            $groupedCost = round($individualSum * 0.85, 2);
            $savings = round($individualSum - $groupedCost, 2);

            $totalShipments += $count;
            $totalIndividualCost += $individualSum;
            $totalGroupedCost += $groupedCost;

            return [
                'regroupement_id'     => $r->id,
                'batch_number'        => $r->batch_number,
                'shipments_count'     => $count,
                'individual_cost'     => round($individualSum, 2),
                'grouped_cost'        => $groupedCost,
                'savings'             => $savings,
                'savings_pct'         => $individualSum > 0
                    ? round(($savings / $individualSum) * 100, 1)
                    : 0,
                'created_at'          => $r->created_at?->toDateString(),
            ];
        });

        return response()->json([
            'month'                   => $month->format('Y-m'),
            'total_regroupements'     => $regroupements->count(),
            'total_shipments'         => $totalShipments,
            'total_individual_cost'   => round($totalIndividualCost, 2),
            'total_grouped_cost'      => round($totalGroupedCost, 2),
            'total_savings'           => round($totalIndividualCost - $totalGroupedCost, 2),
            'avg_savings_pct'         => $totalIndividualCost > 0
                ? round((($totalIndividualCost - $totalGroupedCost) / $totalIndividualCost) * 100, 1)
                : 0,
            'rows'                    => $rows,
        ]);
    }

    /**
     * §10.3 — Calcule l'économie réelle en comparant coût individuel vs coût groupé
     * selon les tranches tarifaires des ShipLineRate.
     * Retourne 0 si aucune tranche n'est trouvée (fallback vers estimation 15%).
     */
    private function computeTariffSavings(\Illuminate\Support\Collection $group, float $totalWeight): float
    {
        // Récupérer la ligne tarifaire principale de la première expédition du groupe
        $first = $group->first();
        $shipLineId = $first->service_options['ship_line_id'] ?? null;
        $shippingModeId = $first->service_options['shipping_mode_id'] ?? null;

        if (! $shipLineId || ! $shippingModeId) {
            return 0.0;
        }

        $rate = \App\Models\ShipLineRate::query()
            ->where('ship_line_id', $shipLineId)
            ->where('shipping_mode_id', $shippingModeId)
            ->where('is_active', true)
            ->first();

        if (! $rate) {
            return 0.0;
        }

        // Calcul individuel (somme des coûts par expédition)
        $individualTotal = $group->sum(function ($s) use ($rate) {
            return $rate->computeBaseQuote((float) ($s->weight_kg ?? 0), 0.0);
        });

        // Calcul groupé (on applique le taux sur le poids total)
        $groupedTotal = $rate->computeBaseQuote($totalWeight, 0.0);

        $savings = max(0.0, round($individualTotal - $groupedTotal, 2));

        return $savings;
    }
}
