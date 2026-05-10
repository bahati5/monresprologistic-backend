<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PickupFailureReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PickupFailureReasonController extends Controller
{
    /** Liste pour les écrans ramassage (staff hors client). */
    public function indexForOperations(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'agency_admin', 'operator', 'driver']), 403);

        if ($request->user()->canAccessAllAgencies()) {
            $rows = PickupFailureReason::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get(['id', 'label', 'agency_id', 'sort_order']);
        } else {
            $agencyId = (int) ($request->user()->agency_id ?? 0);
            $rows = PickupFailureReason::query()
                ->forUserAgency($agencyId > 0 ? $agencyId : null)
                ->get(['id', 'label', 'agency_id', 'sort_order']);
        }

        return response()->json(['reasons' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_settings'), 403);

        $q = PickupFailureReason::query()->orderBy('sort_order')->orderBy('label');
        if ($request->filled('agency_id')) {
            $q->where(function ($w) use ($request) {
                $w->whereNull('agency_id')
                    ->orWhere('agency_id', (int) $request->input('agency_id'));
            });
        }

        return response()->json(['reasons' => $q->paginate(50)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_settings'), 403);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $row = PickupFailureReason::query()->create([
            'label' => $data['label'],
            'agency_id' => $data['agency_id'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return response()->json(['reason' => $row], 201);
    }

    public function update(Request $request, PickupFailureReason $pickupFailureReason): JsonResponse
    {
        abort_unless($request->user()->can('manage_settings'), 403);

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $pickupFailureReason->update($data);

        return response()->json(['reason' => $pickupFailureReason->fresh()]);
    }

    public function destroy(Request $request, PickupFailureReason $pickupFailureReason): JsonResponse
    {
        abort_unless($request->user()->can('manage_settings'), 403);

        if ($pickupFailureReason->pickups()->exists()) {
            $pickupFailureReason->update(['is_active' => false]);

            return response()->json(['message' => 'Motif désactivé (utilisé sur des ramassages).']);
        }

        $pickupFailureReason->delete();

        return response()->json(['message' => 'Motif supprimé.']);
    }
}
