<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\CustomerPackage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerPackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isClient = $user->hasRole('client');

        $q = CustomerPackage::query()->with(['user', 'locker', 'preAlert'])->latest();

        if ($isClient) {
            $q->where('user_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }

        return response()->json([
            'packages' => $q->paginate(20),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $user = $request->user();

        $clients = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->select('id', 'name', 'email')
            ->with('locker:id,code,user_id')
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'locker_code' => $c->locker?->code,
            ]);

        return response()->json([
            'clients' => $clients,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'description' => ['required', 'string', 'max:500'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'pieces_count' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $targetUser = User::with('locker')->find($data['user_id']);
        CustomerPackage::query()->create([
            'reference_code' => CustomerPackage::generateReferenceCode(),
            'user_id' => $data['user_id'],
            'agency_id' => $request->user()->agency_id,
            'locker_id' => $targetUser?->locker?->id,
            'status' => ShipmentStatus::ReceivedAtHub,
            'description' => $data['description'],
            'weight_kg' => $data['weight'] ?? null,
            'length_cm' => $data['length'] ?? null,
            'width_cm' => $data['width'] ?? null,
            'height_cm' => $data['height'] ?? null,
            'declared_value' => $data['declared_value'] ?? null,
            'value_currency' => 'EUR',
            'received_at' => now(),
            'received_by' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['message' => 'Colis client enregistré.']);
    }

    public function show(Request $request, CustomerPackage $customerPackage): JsonResponse
    {
        $customerPackage->load(['user.locker', 'locker', 'preAlert', 'receivedByUser']);

        return response()->json([
            'customerPackage' => $customerPackage,
        ]);
    }

    public function updateStatus(Request $request, CustomerPackage $customerPackage): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::enum(ShipmentStatus::class)],
        ]);

        $customerPackage->update(['status' => ShipmentStatus::from($data['status'])]);

        return response()->json(['message' => 'Statut mis à jour.']);
    }

    protected function authorizeStaff(Request $request): void
    {
        abort_unless(
            $request->user()->can('manage_customer_packages')
                || $request->user()->hasAnyRole(['super_admin', 'agency_admin', 'operator']),
            403
        );
    }
}
