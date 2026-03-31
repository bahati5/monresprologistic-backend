<?php

namespace App\Http\Controllers\Concerns;

use App\Models\City;

trait DenormalizesRecipientLocation
{
    /**
     * Remplit les libellés texte (city, state, country) et aligne les FK à partir de la ville.
     */
    protected function denormalizeRecipientLocationFromCityId(array &$data): void
    {
        $city = City::query()->with('state.country')->find($data['city_id']);
        if (! $city || ! $city->state || ! $city->state->country) {
            return;
        }

        $data['city'] = $city->name;
        $data['state'] = $city->state->name;
        $data['country'] = $city->state->country->name;
        $data['country_id'] = $city->state->country_id;
        $data['state_id'] = $city->state_id;
    }
}
