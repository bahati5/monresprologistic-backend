<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FrontendElementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'route' => $this->route,
            'icon' => $this->icon,
            'order' => $this->order,
            'is_page' => $this->is_page,
            'is_active' => $this->is_active,
            'display_in_sidebar' => $this->display_in_sidebar,
            'menu' => new MenuResource($this->whenLoaded('menu')),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')),
            'created_at' => $this->created_at,
        ];
    }
}
