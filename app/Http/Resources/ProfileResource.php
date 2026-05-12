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
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
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
            'is_recipient' => $this->whenCounted('savedByProfiles', fn () => ($this->saved_by_profiles_count ?? 0) > 0, false),
            'address_book_count' => $this->whenCounted('savedByProfiles', fn () => (int) ($this->saved_by_profiles_count ?? 0)),
            'has_account' => $this->relationLoaded('user') ? $this->user !== null : null,
            'locker_number' => $this->whenLoaded('user', fn () => $this->user?->locker_number),
            'created_at' => $this->created_at,
            // Shipment role counts for badges
            'shipments_as_sender_count' => $this->whenCounted('sentShipments', fn () => (int) ($this->sent_shipments_count ?? 0)),
            'shipments_as_recipient_count' => $this->whenCounted('receivedShipments', fn () => (int) ($this->received_shipments_count ?? 0)),
            'has_shipments_as_sender' => $this->whenCounted('sentShipments', fn () => ($this->sent_shipments_count ?? 0) > 0, false),
            'has_shipments_as_recipient' => $this->whenCounted('receivedShipments', fn () => ($this->received_shipments_count ?? 0) > 0, false),
        ];
    }
}
