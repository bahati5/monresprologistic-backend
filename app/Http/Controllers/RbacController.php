<?php

namespace App\Http\Controllers;

use App\Http\Resources\FrontendElementResource;
use App\Http\Resources\MenuResource;
use App\Http\Resources\PermissionGroupResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\FrontendElement;
use App\Models\Menu;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RbacController extends Controller
{
    /**
     * @return list<string>
     */
    private static function roleFilterNames(mixed $value): array
    {
        if (is_array($value)) {
            $raw = $value;
        } else {
            $raw = preg_split('/\s*[,|]\s*/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_unique(array_filter(array_map('trim', $raw), fn (string $n) => $n !== '')));
    }

    // ─── Roles ──────────────────────────────────────

    public function roles(): JsonResponse
    {
        $roles = Role::withCount(['permissions', 'users'])
            ->with('permissions')
            ->get();

        return response()->json(['roles' => RoleResource::collection($roles)]);
    }

    public function showRole(Role $role): JsonResponse
    {
        $role->load(['permissions', 'permissionGroups.permissions']);

        return response()->json(['role' => new RoleResource($role)]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:125', 'unique:roles,name'],
            'code' => ['nullable', 'string', 'max:125', 'unique:roles,code'],
            'description' => ['nullable', 'string', 'max:500'],
            'level' => ['nullable', 'integer', 'min:0'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'permission_groups' => ['nullable', 'array'],
            'permission_groups.*' => ['string', 'exists:permission_groups,uuid'],
        ]);

        $role = DB::transaction(function () use ($validated) {
            $role = Role::create([
                'name' => $validated['name'],
                'code' => $validated['code'] ?? $validated['name'],
                'description' => $validated['description'] ?? null,
                'level' => $validated['level'] ?? 0,
                'guard_name' => 'web',
            ]);

            if (! empty($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            if (! empty($validated['permission_groups'])) {
                $groupIds = PermissionGroup::whereIn('uuid', $validated['permission_groups'])->pluck('id');
                $role->permissionGroups()->sync($groupIds);
            }

            return $role;
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['role' => new RoleResource($role->load('permissions'))], 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:125', Rule::unique('roles')->ignore($role->id)],
            'code' => ['sometimes', 'string', 'max:125', Rule::unique('roles', 'code')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'level' => ['nullable', 'integer', 'min:0'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'permission_groups' => ['nullable', 'array'],
            'permission_groups.*' => ['string', 'exists:permission_groups,uuid'],
        ]);

        DB::transaction(function () use ($role, $validated) {
            $role->update(collect($validated)->only(['name', 'code', 'description', 'level'])->filter()->all());

            if (array_key_exists('permissions', $validated)) {
                $role->syncPermissions($validated['permissions'] ?? []);
            }

            if (array_key_exists('permission_groups', $validated)) {
                $groupIds = PermissionGroup::whereIn('uuid', $validated['permission_groups'] ?? [])->pluck('id');
                $role->permissionGroups()->sync($groupIds);
            }
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['role' => new RoleResource($role->fresh()->load('permissions'))]);
    }

    public function destroyRole(Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json(['message' => 'Les rôles système ne peuvent pas être supprimés.'], 422);
        }

        $role->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'Rôle supprimé.']);
    }

    // ─── Permissions ────────────────────────────────

    public function permissions(): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'web')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'module' => str_contains($p->name, '.') ? explode('.', $p->name)[0] : 'legacy',
            ]);

        return response()->json(['permissions' => $permissions]);
    }

    public function storePermission(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'La création de permissions est désactivée. Le catalogue est défini par le système.',
        ], 403);
    }

    // ─── Permission Groups ──────────────────────────

    public function permissionGroups(): JsonResponse
    {
        $groups = PermissionGroup::withCount('permissions')
            ->with('permissions')
            ->get();

        return response()->json(['groups' => PermissionGroupResource::collection($groups)]);
    }

    public function storePermissionGroup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:125', 'unique:permission_groups,code'],
            'name' => ['required', 'string', 'max:125'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $group = DB::transaction(function () use ($validated) {
            $group = PermissionGroup::create(collect($validated)->only(['code', 'name', 'description'])->all());

            if (! empty($validated['permissions'])) {
                $permIds = Permission::whereIn('name', $validated['permissions'])->pluck('id');
                $group->permissions()->sync($permIds);
            }

            return $group;
        });

        return response()->json(['group' => new PermissionGroupResource($group->load('permissions'))], 201);
    }

    public function updatePermissionGroup(Request $request, PermissionGroup $permissionGroup): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:125', Rule::unique('permission_groups', 'code')->ignore($permissionGroup->id)],
            'name' => ['sometimes', 'string', 'max:125'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        DB::transaction(function () use ($permissionGroup, $validated) {
            $permissionGroup->update(collect($validated)->only(['code', 'name', 'description', 'is_active'])->all());

            if (array_key_exists('permissions', $validated)) {
                $permIds = Permission::whereIn('name', $validated['permissions'] ?? [])->pluck('id');
                $permissionGroup->permissions()->sync($permIds);
            }
        });

        return response()->json(['group' => new PermissionGroupResource($permissionGroup->fresh()->load('permissions'))]);
    }

    public function destroyPermissionGroup(PermissionGroup $permissionGroup): JsonResponse
    {
        $permissionGroup->delete();

        return response()->json(['message' => 'Groupe supprimé.']);
    }

    // ─── Menus ──────────────────────────────────────

    public function menus(): JsonResponse
    {
        $menus = Menu::withCount('elements')
            ->with('activeElements.permissions')
            ->orderBy('order')
            ->get();

        return response()->json(['menus' => MenuResource::collection($menus)]);
    }

    public function storeMenu(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:125', 'unique:menus,code'],
            'name' => ['required', 'string', 'max:125'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:64'],
            'order' => ['nullable', 'integer'],
        ]);

        $menu = Menu::create($validated);

        return response()->json(['menu' => new MenuResource($menu)], 201);
    }

    public function updateMenu(Request $request, Menu $menu): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:125', Rule::unique('menus', 'code')->ignore($menu->id)],
            'name' => ['sometimes', 'string', 'max:125'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:64'],
            'order' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $menu->update($validated);

        return response()->json(['menu' => new MenuResource($menu->fresh())]);
    }

    // ─── Frontend Elements ──────────────────────────

    public function frontendElements(): JsonResponse
    {
        $elements = FrontendElement::with(['menu', 'permissions'])
            ->orderBy('order')
            ->get();

        return response()->json(['elements' => FrontendElementResource::collection($elements)]);
    }

    public function storeFrontendElement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:125', 'unique:frontend_elements,code'],
            'name' => ['required', 'string', 'max:125'],
            'description' => ['nullable', 'string', 'max:500'],
            'menu_uuid' => ['nullable', 'string', 'exists:menus,uuid'],
            'route' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'order' => ['nullable', 'integer'],
            'is_page' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'display_in_sidebar' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $element = DB::transaction(function () use ($validated) {
            $menuId = null;
            if (! empty($validated['menu_uuid'])) {
                $menuId = Menu::where('uuid', $validated['menu_uuid'])->value('id');
            }

            $element = FrontendElement::create([
                'code' => $validated['code'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'menu_id' => $menuId,
                'route' => $validated['route'],
                'icon' => $validated['icon'] ?? null,
                'order' => $validated['order'] ?? 0,
                'is_page' => $validated['is_page'] ?? true,
                'is_active' => $validated['is_active'] ?? true,
                'display_in_sidebar' => $validated['display_in_sidebar'] ?? true,
            ]);

            if (! empty($validated['permissions'])) {
                $permIds = Permission::whereIn('name', $validated['permissions'])->pluck('id');
                $element->permissions()->sync($permIds);
            }

            return $element;
        });

        return response()->json(['element' => new FrontendElementResource($element->load(['menu', 'permissions']))], 201);
    }

    public function updateFrontendElement(Request $request, FrontendElement $frontendElement): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:125', Rule::unique('frontend_elements', 'code')->ignore($frontendElement->id)],
            'name' => ['sometimes', 'string', 'max:125'],
            'description' => ['nullable', 'string', 'max:500'],
            'menu_uuid' => ['nullable', 'string', 'exists:menus,uuid'],
            'route' => ['sometimes', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'order' => ['nullable', 'integer'],
            'is_page' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'display_in_sidebar' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        DB::transaction(function () use ($frontendElement, $validated) {
            $updateData = collect($validated)->except(['menu_uuid', 'permissions'])->all();

            if (array_key_exists('menu_uuid', $validated)) {
                $updateData['menu_id'] = $validated['menu_uuid']
                    ? Menu::where('uuid', $validated['menu_uuid'])->value('id')
                    : null;
            }

            $frontendElement->update($updateData);

            if (array_key_exists('permissions', $validated)) {
                $permIds = Permission::whereIn('name', $validated['permissions'] ?? [])->pluck('id');
                $frontendElement->permissions()->sync($permIds);
            }
        });

        return response()->json([
            'element' => new FrontendElementResource($frontendElement->fresh()->load(['menu', 'permissions'])),
        ]);
    }

    // ─── Users RBAC ─────────────────────────────────

    public function users(Request $request): JsonResponse
    {
        $query = User::with(['profile', 'roles', 'agency'])
            ->when($request->filled('search'), fn ($q) => $q->where(function ($sub) use ($request) {
                $like = '%'.$request->search.'%';
                $sub->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            }))
            ->when($request->filled('role'), function ($q) use ($request) {
                $names = self::roleFilterNames($request->input('role'));
                if ($names !== []) {
                    $q->whereHas('roles', fn ($sub) => $sub->whereIn('name', $names));
                }
            })
            ->when($request->filled('agency_id'), fn ($q) => $q->where('agency_id', $request->agency_id))
            ->orderByDesc('created_at');

        $users = $query->paginate($request->integer('per_page', 25));

        return response()->json($users);
    }

    public function assignRole(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role_uuid' => ['required', 'string', 'exists:roles,uuid'],
        ]);

        $currentUser = $request->user();
        $newRole = Role::where('uuid', $validated['role_uuid'])->firstOrFail();

        if ($newRole->name === 'super_admin' && ! $currentUser->isSuperAdmin()) {
            return response()->json(['message' => 'Seul un super_admin peut assigner le rôle super_admin.'], 403);
        }

        DB::transaction(function () use ($user, $newRole) {
            $user->syncRoles([$newRole->name]);
            $user->update(['role_id' => $newRole->id]);
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'Rôle assigné.', 'user' => new UserResource($user->fresh()->load('profile'))]);
    }

    public function activateUser(User $user): JsonResponse
    {
        $profile = $user->profile;
        if ($profile) {
            $profile->update(['is_active' => true]);
        }

        return response()->json(['message' => 'Utilisateur activé.']);
    }

    public function deactivateUser(User $user): JsonResponse
    {
        $profile = $user->profile;
        if ($profile) {
            $profile->update(['is_active' => false]);
        }

        return response()->json(['message' => 'Utilisateur désactivé.']);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update(['password' => bcrypt($validated['password'])]);

        return response()->json(['message' => 'Mot de passe réinitialisé.']);
    }

    // ─── Auth enriched endpoints ────────────────────

    public function myPermissions(Request $request): JsonResponse
    {
        return response()->json([
            'permissions' => $request->user()->getAllEffectivePermissionCodes(),
        ]);
    }

    public function myNavigation(Request $request): JsonResponse
    {
        $user = $request->user();
        $menus = $user->getAccessibleMenus()->load('activeElements.permissions');
        $pages = $user->getAccessiblePages();

        $tree = $menus->map(function ($menu) use ($pages) {
            return [
                'uuid' => $menu->uuid,
                'code' => $menu->code,
                'name' => $menu->name,
                'icon' => $menu->icon,
                'order' => $menu->order,
                'pages' => $pages->where('menu_id', $menu->id)
                    ->where('display_in_sidebar', true)
                    ->sortBy('order')
                    ->values()
                    ->map(fn ($p) => [
                        'uuid' => $p->uuid,
                        'code' => $p->code,
                        'name' => $p->name,
                        'route' => $p->route,
                        'icon' => $p->icon,
                        'order' => $p->order,
                        'permissions' => $p->permissions->pluck('name'),
                    ]),
            ];
        });

        return response()->json(['navigation' => $tree]);
    }

    public function checkPermissions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        $user = $request->user();
        $result = [];
        foreach ($validated['permissions'] as $code) {
            $result[$code] = $user->hasEffectivePermission($code);
        }

        return response()->json($result);
    }
}
