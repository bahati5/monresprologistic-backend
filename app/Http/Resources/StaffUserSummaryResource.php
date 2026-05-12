<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Résumé staff pour GET /api/users (liste CRM). */
class StaffUserSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->roles->first();

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'roles' => $this->roles->map(fn ($r) => [
                'uuid' => $r->uuid,
                'name' => $r->name,
                'code' => $r->code ?? $r->name,
            ])->values(),
            'role' => $role?->name,
            'agency_uuid' => $this->agency?->uuid,
            'agency_name' => $this->agency?->name,
            'is_active' => $this->profile ? (bool) $this->profile->is_active : ($this->email_verified_at !== null),
            'created_at' => $this->created_at,
        ];
    }
}
