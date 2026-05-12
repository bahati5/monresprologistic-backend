<?php

namespace App\Http\Controllers;

use App\Http\Resources\StaffUserSummaryResource;
use App\Models\Agency;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class UserManagementController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    private const STAFF_ROLE_NAMES = ['super_admin', 'agency_admin', 'operator', 'driver', 'customs_agent'];

    /**
     * @return list<string>
     */
    private function assignableRoleNames(User $actor): array
    {
        if ($actor->hasRole('super_admin')) {
            return ['super_admin', 'agency_admin', 'operator', 'driver', 'customs_agent'];
        }

        return ['agency_admin', 'operator', 'driver', 'customs_agent'];
    }

    private function authorizeManagedUser(User $actor, User $target): void
    {
        if (! $target->hasAnyRole(self::STAFF_ROLE_NAMES)) {
            abort(404);
        }

        if (! $actor->canAccessAllAgencies() && (int) $target->agency_id !== (int) $actor->agency_id) {
            abort(403, 'Accès refusé pour cet utilisateur.');
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = User::query()
            ->with(['agency', 'roles', 'profile'])
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

        if ($request->filled('agency_uuid')) {
            $agencyId = Agency::where('uuid', $request->input('agency_uuid'))->value('id');
            if ($agencyId) {
                $query->where('agency_id', $agencyId);
            }
        }

        $users = $query->latest()->paginate(25)->withQueryString();

        return response()->json([
            'users' => StaffUserSummaryResource::collection($users),
            'filters' => $request->only(['search', 'role', 'agency_uuid']),
            'agencies' => $this->getAgenciesUuid($user),
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
            'agency_uuid' => ['nullable', 'string', 'exists:agencies,uuid'],
            'role' => ['required', 'string', Rule::in($assignable)],
        ]);

        $agencyId = null;
        if (! empty($data['agency_uuid'])) {
            $agencyId = Agency::where('uuid', $data['agency_uuid'])->value('id');
        }
        $agencyId = $agencyId ?? $request->user()->agency_id;

        if (! $request->user()->canAccessAllAgencies()) {
            $agencyId = $request->user()->agency_id;
        }

        $newUser = DB::transaction(function () use ($data, $agencyId) {
            $nameParts = explode(' ', trim($data['name']), 2);
            $first = $nameParts[0] !== '' ? $nameParts[0] : 'Staff';
            $last = $nameParts[1] ?? '';

            $profile = Profile::create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'agency_id' => $agencyId,
                'type' => 'individual',
                'is_staff' => true,
                'is_client' => false,
                'is_active' => true,
            ]);

            return User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'agency_id' => $agencyId,
                'profile_id' => $profile->id,
                'email_verified_at' => now(),
            ]);
        });

        $newUser->assignRole($data['role']);

        return response()->json(['message' => 'Utilisateur créé.', 'uuid' => $newUser->uuid], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeManagedUser($request->user(), $user);

        $assignable = $this->assignableRoleNames($request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'agency_uuid' => ['nullable', 'string', 'exists:agencies,uuid'],
            'role' => ['required', 'string', Rule::in($assignable)],
        ]);

        $agencyId = null;
        if (! empty($data['agency_uuid'])) {
            $agencyId = Agency::where('uuid', $data['agency_uuid'])->value('id');
        }

        if (! $request->user()->canAccessAllAgencies()) {
            $agencyId = $request->user()->agency_id;
        }

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'agency_id' => $agencyId,
        ]);

        $user->syncRoles([$data['role']]);

        $user->refresh();
        $this->ensureStaffProfile($user);
        $this->syncStaffProfileFromUser($user, $data['name'], $data['email'], $data['phone'] ?? null, $agencyId);

        return response()->json(['message' => 'Utilisateur mis à jour.']);
    }

    public function toggleActive(Request $request, User $user): JsonResponse
    {
        $this->authorizeManagedUser($request->user(), $user);

        $user->refresh();
        $profile = $this->ensureStaffProfile($user);

        if ($request->user()->is($user) && $profile->is_active) {
            abort(422, 'Vous ne pouvez pas désactiver votre propre compte.');
        }

        $profile->update(['is_active' => ! $profile->is_active]);

        return response()->json(['message' => 'Statut modifié.']);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeManagedUser($request->user(), $user);

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $user->update(['password' => $validated['password']]);

        return response()->json(['message' => 'Mot de passe réinitialisé.']);
    }

    private function getAgenciesUuid(User $user): Collection
    {
        if ($user->canAccessAllAgencies()) {
            return Agency::where('is_active', true)->get(['uuid', 'name']);
        }

        return Agency::where('id', $user->agency_id)->get(['uuid', 'name']);
    }

    /**
     * La connexion staff (LoginRequest) résout l'utilisateur via le profil (email / téléphone).
     * Tout compte créé ou géré ici doit donc avoir un profil staff lié.
     */
    private function ensureStaffProfile(User $user): Profile
    {
        $user->loadMissing('profile');

        if ($user->profile) {
            return $user->profile;
        }

        $orphan = Profile::query()
            ->where('email', $user->email)
            ->whereDoesntHave('user')
            ->first();

        if ($orphan) {
            $nameParts = explode(' ', trim((string) $user->name), 2);
            $orphan->update([
                'first_name' => ($nameParts[0] !== '' ? $nameParts[0] : 'Staff'),
                'last_name' => $nameParts[1] ?? '',
                'phone' => $user->phone ?? $orphan->phone,
                'agency_id' => $user->agency_id ?? $orphan->agency_id,
                'is_staff' => true,
                'is_client' => false,
                'is_active' => $user->email_verified_at !== null,
            ]);
            $user->update(['profile_id' => $orphan->id]);
            $user->setRelation('profile', $orphan->fresh());

            return $user->profile;
        }

        $nameParts = explode(' ', trim((string) $user->name), 2);
        $profile = Profile::create([
            'first_name' => ($nameParts[0] !== '' ? $nameParts[0] : 'Staff'),
            'last_name' => $nameParts[1] ?? '',
            'email' => $user->email,
            'phone' => $user->phone,
            'agency_id' => $user->agency_id,
            'type' => 'individual',
            'is_staff' => true,
            'is_client' => false,
            'is_active' => $user->email_verified_at !== null,
        ]);

        $user->update(['profile_id' => $profile->id]);
        $user->setRelation('profile', $profile);

        return $profile;
    }

    private function syncStaffProfileFromUser(User $user, string $name, string $email, ?string $phone, ?int $agencyId): void
    {
        $profile = $user->profile;
        if (! $profile) {
            return;
        }

        $nameParts = explode(' ', trim($name), 2);

        $profile->update([
            'first_name' => ($nameParts[0] !== '' ? $nameParts[0] : 'Staff'),
            'last_name' => $nameParts[1] ?? '',
            'email' => $email,
            'phone' => $phone,
            'agency_id' => $agencyId,
        ]);
    }
}
