<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code ?? $this->name,
            'name' => $this->name,
            'description' => $this->description ?? null,
            'is_system' => (bool) ($this->is_system ?? false),
            'level' => $this->level ?? 0,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')),
            'groups' => $this->when(
                $this->relationLoaded('permissionGroups'),
                fn () => PermissionGroupResource::collection($this->permissionGroups),
            ),
            /** Compte direct Spatie ; pour super_admin sans pivot (base incomplète), on affiche le catalogue total. */
            'permissions_count' => $this->permissionsCountForDisplay(),
            'users_count' => $this->whenCounted('users'),
            'groups_count' => $this->when(
                $this->relationLoaded('permissionGroups'),
                fn () => $this->permissionGroups->count()
            ),
            'created_at' => $this->created_at,
        ];
    }

    private function permissionsCountForDisplay(): int
    {
        $role = $this->resource;

        $direct = (int) ($role->permissions_count
            ?? ($role->relationLoaded('permissions') ? $role->permissions->count() : 0));

        if ($role->name === 'super_admin' && $direct === 0) {
            return (int) Permission::query()->count();
        }

        return $direct;
    }
}
