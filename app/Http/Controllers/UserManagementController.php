<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class UserManagementController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    /** Rôles « staff » listés en gestion utilisateurs. */
    private const STAFF_ROLE_NAMES = ['super_admin', 'agency_admin', 'operator', 'driver', 'customs_agent'];

    /**
     * Rôles assignables par l'utilisateur connecté (PRD §4 — plus legacy admin/employee).
     *
     * @return list<string>
     */
    private function assignableRoleNames(User $actor): array
    {
        if ($actor->hasRole('super_admin')) {
            return ['super_admin', 'agency_admin', 'operator', 'driver', 'customs_agent'];
        }

        return ['agency_admin', 'operator', 'driver', 'customs_agent'];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = User::query()
            ->with(['agency', 'roles'])
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLE_NAMES));

        if (! $user->canAccessAllAgencies()) {
            $query->where('agency_id', $user->agency_id);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->role($request->input('role'));
        }

        if ($request->filled('agency_id')) {
            $query->where('agency_id', $request->input('agency_id'));
        }

        $users = $query->latest()->paginate(25)->withQueryString();

        return response()->json([
            'users' => $users,
            'filters' => $request->only(['search', 'role', 'agency_id']),
            'agencies' => $this->getAgencies($user),
            'availableRoles' => $this->assignableRoleNames($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $assignable = $this->assignableRoleNames($request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', Rules\Password::defaults()],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'role' => ['required', 'string', Rule::in($assignable)],
        ]);

        $newUser = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'agency_id' => $data['agency_id'] ?? $request->user()->agency_id,
            'email_verified_at' => now(),
        ]);

        $newUser->assignRole($data['role']);

        return response()->json(['message' => 'Utilisateur créé.']);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $assignable = $this->assignableRoleNames($request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:32'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'role' => ['required', 'string', Rule::in($assignable)],
        ]);

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'agency_id' => $data['agency_id'],
        ]);

        $user->syncRoles([$data['role']]);

        return response()->json(['message' => 'Utilisateur mis à jour.']);
    }

    public function toggleActive(User $user): JsonResponse
    {
        if ($user->email_verified_at) {
            $user->update(['email_verified_at' => null]);
        } else {
            $user->update(['email_verified_at' => now()]);
        }

        return response()->json(['message' => 'Statut modifié.']);
    }

    private function getAgencies(User $user): \Illuminate\Support\Collection
    {
        if ($user->canAccessAllAgencies()) {
            return Agency::where('is_active', true)->get(['id', 'name']);
        }

        return Agency::where('id', $user->agency_id)->get(['id', 'name']);
    }
}
