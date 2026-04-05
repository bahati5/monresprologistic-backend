<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_secondary' => $this->phone_secondary,
            'address' => $this->address,
            'landmark' => $this->landmark,
            'zip_code' => $this->zip_code,
            'city' => $this->whenLoaded('city', fn () => [
                'id' => $this->city->id,
                'name' => $this->city->name,
            ]),
            'state' => $this->whenLoaded('state', fn () => [
                'id' => $this->state->id,
                'name' => $this->state->name,
            ]),
            'country' => $this->whenLoaded('country', fn () => [
                'id' => $this->country->id,
                'name' => $this->country->name,
                'code' => $this->country->code,
                'iso2' => $this->country->iso2,
                'emoji' => $this->country->emoji,
            ]),
            'agency_id' => $this->agency_id,
            'is_active' => $this->is_active,
            'is_client' => $this->is_client,
            'is_staff' => $this->is_staff,
            'has_account' => $this->relationLoaded('user') ? $this->user !== null : null,
            'locker_number' => $this->whenLoaded('user', fn () => $this->user?->locker_number),
            'created_at' => $this->created_at,
        ];
    }
}
