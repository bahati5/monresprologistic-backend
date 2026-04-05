<?php

namespace App\Http\Controllers;

use App\Enums\PickupStatus;
use App\Models\Pickup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PickupController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Pickup::query()->with(['client', 'agency', 'driver', 'shipment']);
        $this->scopeByAgency($q, $user);

        return response()->json([
            'pickups' => $q->latest()->paginate(30),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'address_text' => ['nullable', 'string', 'max:2000'],
            'requested_window' => ['nullable', 'string', 'max:500'],
            'shipment_id' => ['nullable', 'exists:shipments,id'],
        ]);

        $user = $request->user();

        $pickup = Pickup::query()->create([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'shipment_id' => $data['shipment_id'] ?? null,
            'status' => PickupStatus::Draft,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'address_text' => $data['address_text'] ?? null,
            'requested_window' => $data['requested_window'] ?? null,
        ]);

        return response()->json(['message' => 'Ramassage demandé.', 'pickup' => $pickup], 201);
    }

    public function assign(Request $request, Pickup $pickup): JsonResponse
    {
        $this->authorize('update', $pickup);

        $data = $request->validate([
            'assigned_driver_id' => ['required', 'exists:users,id'],
        ]);

        $pickup->update([
            'assigned_driver_id' => $data['assigned_driver_id'],
            'status' => PickupStatus::DriverAssigned,
        ]);

        return response()->json(['message' => 'Chauffeur assigné.']);
    }

    public function updateStatus(Request $request, Pickup $pickup): JsonResponse
    {
        $this->authorize('update', $pickup);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::enum(PickupStatus::class)],
        ]);

        $next = PickupStatus::from($data['status']);
        $current = $pickup->status ?? PickupStatus::Draft;
        if (! $current->canTransitionTo($next)) {
            return response()->json(['message' => 'Transition non autorisée.'], 422);
        }

        $pickup->update(['status' => $next]);

        return response()->json(['message' => 'Statut mis à jour.']);
    }
}
