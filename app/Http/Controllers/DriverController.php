<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class DriverController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = User::query()
            ->role('driver')
            ->with(['agency', 'driverProfile']);

        if (! $user->canAccessAllAgencies()) {
            $query->where('agency_id', $user->agency_id);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('driverProfile', fn ($dp) => $dp->where('vehicle_plate', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('agency_id')) {
            $query->where('agency_id', $request->input('agency_id'));
        }

        if ($request->filled('available')) {
            $available = $request->boolean('available');
            $query->whereHas('driverProfile', fn ($q) => $q->where('is_available', $available));
        }

        $drivers = $query->latest()->paginate(25)->withQueryString();

        return response()->json([
            'drivers' => $drivers,
            'filters' => $request->only(['search', 'agency_id', 'available']),
            'agencies' => $this->getAgencies($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', Rules\Password::defaults()],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'license_number' => ['nullable', 'string', 'max:64'],
            'license_expiry' => ['nullable', 'date'],
            'vehicle_type' => ['nullable', 'string', 'max:64'],
            'vehicle_plate' => ['nullable', 'string', 'max:32'],
            'vehicle_brand' => ['nullable', 'string', 'max:64'],
            'coverage_zone' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:100'],
            'emergency_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $driver = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'agency_id' => $data['agency_id'] ?? $request->user()->agency_id,
            'email_verified_at' => now(),
        ]);

        $driver->assignRole('driver');

        DriverProfile::create([
            'user_id' => $driver->id,
            'license_number' => $data['license_number'] ?? null,
            'license_expiry' => $data['license_expiry'] ?? null,
            'vehicle_type' => $data['vehicle_type'] ?? null,
            'vehicle_plate' => $data['vehicle_plate'] ?? null,
            'vehicle_brand' => $data['vehicle_brand'] ?? null,
            'coverage_zone' => $data['coverage_zone'] ?? null,
            'emergency_contact' => $data['emergency_contact'] ?? null,
            'emergency_phone' => $data['emergency_phone'] ?? null,
        ]);

        return response()->json(['message' => 'Chauffeur créé.']);
    }

    public function update(Request $request, User $driver): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email,' . $driver->id],
            'phone' => ['nullable', 'string', 'max:32'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'license_number' => ['nullable', 'string', 'max:64'],
            'license_expiry' => ['nullable', 'date'],
            'vehicle_type' => ['nullable', 'string', 'max:64'],
            'vehicle_plate' => ['nullable', 'string', 'max:32'],
            'vehicle_brand' => ['nullable', 'string', 'max:64'],
            'coverage_zone' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:100'],
            'emergency_phone' => ['nullable', 'string', 'max:32'],
            'is_available' => ['boolean'],
        ]);

        $driver->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'agency_id' => $data['agency_id'],
        ]);

        $driver->driverProfile()->updateOrCreate(
            ['user_id' => $driver->id],
            [
                'license_number' => $data['license_number'] ?? null,
                'license_expiry' => $data['license_expiry'] ?? null,
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'vehicle_brand' => $data['vehicle_brand'] ?? null,
                'coverage_zone' => $data['coverage_zone'] ?? null,
                'emergency_contact' => $data['emergency_contact'] ?? null,
                'emergency_phone' => $data['emergency_phone'] ?? null,
                'is_available' => $data['is_available'] ?? true,
            ]
        );

        return response()->json(['message' => 'Chauffeur mis à jour.']);
    }

    public function toggleActive(User $driver): JsonResponse
    {
        if ($driver->email_verified_at) {
            $driver->update(['email_verified_at' => null]);
        } else {
            $driver->update(['email_verified_at' => now()]);
        }

        return response()->json(['message' => 'Statut du chauffeur modifié.']);
    }

    private function getAgencies(User $user): \Illuminate\Support\Collection
    {
        if ($user->canAccessAllAgencies()) {
            return Agency::where('is_active', true)->get(['id', 'name']);
        }

        return Agency::where('id', $user->agency_id)->get(['id', 'name']);
    }
}
