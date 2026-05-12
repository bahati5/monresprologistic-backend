<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_roles'), 403);

        $roles = Role::query()->orderBy('name')->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->all(),
            'users_count' => $role->users()->count(),
            'is_system' => in_array($role->name, ['super_admin', 'agency_admin', 'operator', 'driver', 'customs_agent', 'client'], true),
        ]);

        $permissions = Permission::query()->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('manage_roles'), 403);

        if ($role->name === 'super_admin' && ! $request->user()->hasRole('super_admin')) {
            abort(403, 'Seul un super_admin peut modifier ce rôle.');
        }

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->syncPermissions($data['permissions']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'Permissions du rôle mises à jour.']);
    }

    public function storeRole(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        if (! empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'Rôle créé.', 'role' => ['id' => $role->id, 'name' => $role->name]], 201);
    }

    public function destroyRole(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);

        $systemRoles = ['super_admin', 'agency_admin', 'operator', 'driver', 'customs_agent', 'client'];
        if (in_array($role->name, $systemRoles, true)) {
            return response()->json(['message' => 'Impossible de supprimer un rôle système.'], 422);
        }

        if ($role->users()->count() > 0) {
            return response()->json(['message' => 'Ce rôle est encore assigné à des utilisateurs.'], 422);
        }

        $role->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'Rôle supprimé.']);
    }

    public function storePermission(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);

        return response()->json([
            'message' => 'La création de permissions est désactivée. Le catalogue est défini par le système.',
        ], 403);
    }

    public function userPermissions(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('manage_roles'), 403);

        return response()->json([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'roles' => $user->getRoleNames()->all(),
            'role_permissions' => $user->getPermissionsViaRoles()->pluck('name')->all(),
            'direct_permissions' => $user->getDirectPermissions()->pluck('name')->all(),
            'all_permissions' => $user->getAllPermissions()->pluck('name')->all(),
        ]);
    }

    public function updateUserPermissions(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('manage_roles'), 403);

        if ($user->hasRole('super_admin') && ! $request->user()->hasRole('super_admin')) {
            abort(403);
        }

        $data = $request->validate([
            'direct_permissions' => ['present', 'array'],
            'direct_permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $user->syncPermissions($data['direct_permissions']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'Permissions directes mises à jour.']);
    }
}
