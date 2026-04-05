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

        return null;
    }
}
