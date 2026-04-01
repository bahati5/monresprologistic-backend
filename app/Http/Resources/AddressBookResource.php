<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressBookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alias' => $this->alias,
            'is_default' => $this->is_default,
            'notes' => $this->notes,
            'contact' => new ProfileResource($this->whenLoaded('profile')),
            'created_at' => $this->created_at,
        ];
    }
}
