<?php

namespace App\Http\Controllers;

use App\Models\Pickup;
use App\Models\Status;
use App\Models\User;
use App\Services\PickupWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PickupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Pickup::query()->with(['client', 'driver', 'status'])->latest();

        if ($user->hasRole('client')) {
            $q->where('user_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }

        $drivers = collect();
        if ($user->can('assign_drivers')) {
            $drivers = User::query()
                ->role('driver')
                ->when(! $user->canAccessAllAgencies(), fn ($uq) => $uq->where('agency_id', $user->agency_id))
                ->orderBy('name')
                ->get(['id', 'name', 'email']);
        }

        $workflow = app(PickupWorkflowService::class);
        $pickups = $q->paginate(20);
        $pickups->getCollection()->transform(function ($p) use ($workflow) {
            $p->workflow_steps = $workflow->buildStepsForPickup($p);
            $p->available_transitions = $workflow->getAvailableTransitions($p);

            return $p;
        });

        return response()->json([
            'pickups' => $pickups,
            'drivers' => $drivers,
        ]);
    }

    public function create(): JsonResponse
    {
        return response()->json(['message' => 'OK']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'address_text' => ['nullable', 'string', 'max:500'],
            'requested_window' => ['nullable', 'string', 'max:255'],
        ]);

        $status = Status::query()->where('code', 'created')->first();

        Pickup::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
            'agency_id' => $request->user()->agency_id,
            'status_id' => $status?->id,
        ]);

        return response()->json(['message' => 'Demande de ramassage enregistrée.']);
    }

    public function assign(Request $request, Pickup $pickup): JsonResponse
    {
        abort_unless($request->user()->can('assign_drivers'), 403);

        $data = $request->validate([
            'assigned_driver_id' => ['required', 'exists:users,id'],
        ]);

        $pickup->update($data);

        $driverAssignedStatus = Status::query()->where('code', 'pickup_driver_assigned')->first();
        if ($driverAssignedStatus) {
            $pickup->update(['status_id' => $driverAssignedStatus->id]);
        }

        return response()->json(['message' => 'Chauffeur assigné.']);
    }

    public function updateStatus(Request $request, Pickup $pickup): JsonResponse
    {
        $data = $request->validate([
            'status_id' => ['required', 'exists:statuses,id'],
        ]);

        $pickup->update(['status_id' => $data['status_id']]);

        return response()->json(['message' => 'Statut mis à jour.']);
    }
}
