<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'permissions_count' => $this->whenCounted('permissions'),
            'users_count' => $this->whenCounted('users'),
            'groups_count' => $this->when(
                $this->relationLoaded('permissionGroups'),
                fn () => $this->permissionGroups->count()
            ),
            'created_at' => $this->created_at,
        ];
    }
}
