<?php

namespace App\Http\Controllers;

use App\Models\Consolidation;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsolidationController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $shipments = Shipment::query()
            ->whereNull('consolidation_id')
            ->whereNull('master_shipment_id')
            ->with(['sender', 'status']);

        $this->scopeShipmentsFor($shipments, $user);

        $consolidations = Consolidation::query()
            ->with(['user', 'shipments', 'status'])
            ->latest()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->take(50)
            ->get();

        $consolidationStatuses = Status::query()
            ->whereIn('code', ['cons_created', 'cons_items_added', 'cons_closed', 'cons_shipped', 'cons_in_transit', 'cons_arrived', 'cons_distributed'])
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'color_hex']);

        $users = User::query()
            ->when(! $user->canAccessAllAgencies(), fn ($uq) => $uq->where('agency_id', $user->agency_id))
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'email']);

        return response()->json([
            'availableShipments' => $shipments->get(),
            'consolidations' => $consolidations,
            'users' => $users,
            'consolidationStatuses' => $consolidationStatuses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'shipment_ids' => ['required', 'array', 'min:2'],
            'shipment_ids.*' => ['exists:shipments,id'],
        ]);

        $user = $request->user();
        abort_unless($user->can('create_consolidations'), 403);

        $consolidation = DB::transaction(function () use ($request, $user) {
            $master = 'MRP-C'.strtoupper(Str::random(6));
            while (Consolidation::query()->where('master_tracking', $master)->exists()) {
                $master = 'MRP-C'.strtoupper(Str::random(6));
            }

            $shipments = Shipment::query()->whereIn('id', $request->shipment_ids)->get();
            $weight = $shipments->sum(fn ($s) => (float) ($s->weight_kg ?? 0));
            $volume = $shipments->sum(function ($s) {
                $l = (float) ($s->length_cm ?? 0);
                $w = (float) ($s->width_cm ?? 0);
                $h = (float) ($s->height_cm ?? 0);

                return $l * $w * $h / 1_000_000;
            });

            $status = Status::query()->where('code', 'cons_created')->first() ?? Status::query()->where('code', 'in_transit')->first();

            $c = Consolidation::query()->create([
                'master_tracking' => $master,
                'user_id' => $request->user_id,
                'agency_id' => $user->agency_id,
                'status_id' => $status?->id,
                'total_weight_kg' => $weight,
                'total_volume_m3' => $volume,
            ]);

            foreach ($shipments as $shipment) {
                $shipment->update(['consolidation_id' => $c->id, 'master_shipment_id' => null]);
                $c->shipments()->attach($shipment->id);
            }

            return $c;
        });

        return redirect()->route('consolidations.index')->with('success', 'Consolidation '.$consolidation->master_tracking.' créée.');
    }

    public function updateStatus(Request $request, Consolidation $consolidation): JsonResponse
    {
        $request->validate([
            'status_id' => ['required', 'exists:statuses,id'],
        ]);
        $consolidation->update(['status_id' => $request->status_id]);

        return response()->json(['message' => 'Statut mis à jour.']);
    }
}
