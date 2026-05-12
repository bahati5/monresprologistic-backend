<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->primaryRole ?? $this->roles()->first();

        $avatarUrl = null;
        if ($this->avatar_path) {
            $avatarUrl = Storage::disk('public')->url($this->avatar_path);
        }

        $base = [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'theme_preference' => $this->theme_preference,
            'avatar_url' => $avatarUrl,
            'phone' => $this->phone,
            'locker_number' => $this->locker_number,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'agency_uuid' => $this->agency?->uuid,
            'agency_id' => $this->agency_id,
            'profile' => new ProfileResource($this->whenLoaded('profile')),
            'created_at' => $this->created_at,
        ];

        if ($role) {
            $base['role'] = [
                'uuid' => $role->uuid ?? null,
                'code' => $role->code ?? $role->name,
                'name' => $role->name,
                'description' => $role->description ?? null,
            ];
        }

        $base['effective_permissions'] = $this->getAllEffectivePermissionCodes();

        $base['accessible_menus'] = $this->getAccessibleMenus()->map(fn ($m) => [
            'uuid' => $m->uuid,
            'code' => $m->code,
            'name' => $m->name,
            'icon' => $m->icon,
            'order' => $m->order,
        ]);

        $base['accessible_pages'] = $this->getAccessiblePages()->map(fn ($p) => [
            'uuid' => $p->uuid,
            'code' => $p->code,
            'name' => $p->name,
            'route' => $p->route,
            'icon' => $p->icon,
            'order' => $p->order,
            'is_page' => $p->is_page,
            'is_active' => $p->is_active,
            'display_in_sidebar' => $p->display_in_sidebar,
            'menu' => $p->menu ? [
                'uuid' => $p->menu->uuid,
                'code' => $p->menu->code,
                'name' => $p->menu->name,
            ] : null,
            'permissions' => $p->permissions->pluck('name'),
        ]);

        return $base;
    }
}
