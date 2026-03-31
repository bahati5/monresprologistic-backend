<?php

namespace App\Services;

use App\Models\ShippingRate;

/**
 * Sélectionne le tarif le plus spécifique pour un contexte donné.
 * Wildcards : pas de ligne pivot / FK null = « tous ».
 */
class ShippingRateResolver
{
    /**
     * @return array{rate: ShippingRate|null, score: int}
     */
    public function resolve(
        ?int $agencyId,
        ?int $originCountryId,
        ?int $destCountryId,
        ?int $shippingModeId,
    ): array {
        $candidates = ShippingRate::query()
            ->where('is_active', true)
            ->with([
                'shippingModes',
                'originCountries',
                'destinationCountries',
            ])
            ->get();

        $best = null;
        $bestScore = -1;

        foreach ($candidates as $rate) {
            if (! $this->agencyMatches($rate, $agencyId)) {
                continue;
            }

            $modeOk = $this->modeMatches($rate, $shippingModeId);
            if (! $modeOk['matches']) {
                continue;
            }
            $originOk = $this->originMatches($rate, $originCountryId);
            if (! $originOk['matches']) {
                continue;
            }
            $destOk = $this->destinationMatches($rate, $destCountryId);
            if (! $destOk['matches']) {
                continue;
            }

            $score = $modeOk['specificity'] + $originOk['specificity'] + $destOk['specificity']
                + $this->agencySpecificity($rate, $agencyId);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $rate;
            }
        }

        return ['rate' => $best, 'score' => $bestScore];
    }

    public function computeBaseQuote(ShippingRate $rate, float $billableWeightKg, float $volumeM3): float
    {
        $price = (float) $rate->unit_price;

        return match ($rate->pricing_type) {
            'per_volume' => round($price * max($volumeM3, 0), 2),
            'flat' => round($price, 2),
            default => round($price * max($billableWeightKg, 0), 2),
        };
    }

    protected function agencyMatches(ShippingRate $rate, ?int $agencyId): bool
    {
        if ($rate->agency_id === null) {
            return true;
        }

        return $agencyId !== null && (int) $rate->agency_id === $agencyId;
    }

    protected function agencySpecificity(ShippingRate $rate, ?int $agencyId): int
    {
        if ($rate->agency_id === null) {
            return 0;
        }

        return $agencyId !== null && (int) $rate->agency_id === $agencyId ? 1 : 0;
    }

    /**
     * @return array{matches: bool, specificity: int}
     */
    protected function modeMatches(ShippingRate $rate, ?int $shippingModeId): array
    {
        $pivotIds = $rate->shippingModes->pluck('id')->all();
        $legacyId = $rate->shipping_mode_id ? (int) $rate->shipping_mode_id : null;
        $ids = array_values(array_unique(array_filter([...$pivotIds, $legacyId])));

        if ($ids === []) {
            return ['matches' => true, 'specificity' => 0];
        }

        if ($shippingModeId === null) {
            return ['matches' => false, 'specificity' => 0];
        }

        $ok = in_array($shippingModeId, $ids, true);

        return ['matches' => $ok, 'specificity' => $ok ? 2 : 0];
    }

    /**
     * @return array{matches: bool, specificity: int}
     */
    protected function originMatches(ShippingRate $rate, ?int $countryId): array
    {
        $pivotIds = $rate->originCountries->pluck('id')->all();
        $legacyId = $rate->origin_country_id ? (int) $rate->origin_country_id : null;
        $ids = array_values(array_unique(array_filter([...$pivotIds, $legacyId])));

        if ($ids === []) {
            return ['matches' => true, 'specificity' => 0];
        }

        if ($countryId === null) {
            return ['matches' => false, 'specificity' => 0];
        }

        $ok = in_array($countryId, $ids, true);

        return ['matches' => $ok, 'specificity' => $ok ? 2 : 0];
    }

    /**
     * @return array{matches: bool, specificity: int}
     */
    protected function destinationMatches(ShippingRate $rate, ?int $countryId): array
    {
        $pivotIds = $rate->destinationCountries->pluck('id')->all();
        $legacyId = $rate->dest_country_id ? (int) $rate->dest_country_id : null;
        $ids = array_values(array_unique(array_filter([...$pivotIds, $legacyId])));

        if ($ids === []) {
            return ['matches' => true, 'specificity' => 0];
        }

        if ($countryId === null) {
            return ['matches' => false, 'specificity' => 0];
        }

        $ok = in_array($countryId, $ids, true);

        return ['matches' => $ok, 'specificity' => $ok ? 2 : 0];
    }
}
