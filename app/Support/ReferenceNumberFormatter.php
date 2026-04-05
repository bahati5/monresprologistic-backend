<?php

namespace App\Support;

use App\Models\Country;
use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * Remplace des placeholders {@see apply()} dans un modèle de numéro.
 *
 * Clés supportées : prefix, year, month, day, seq, id, random, randnum,
 * country, country_code, week, quarter, hub_brand
 */
final class ReferenceNumberFormatter
{
    /**
     * Pays, code ISO, semaine ISO (01–53), trimestre (1–4), marque hub — aligné sur {@see apply()} + générateurs.
     * Garder la même sémantique côté frontend (nomenclaturePreview.ts).
     *
     * @return array<string, string>
     */
    public static function localeAndCalendarReplacements(?CarbonInterface $at = null): array
    {
        $now = $at ?? now();
        $countryId = (int) (Setting::getValue('country_id', '') ?: 0);
        $iso2 = '';
        if ($countryId > 0) {
            $raw = Country::query()->whereKey($countryId)->value('iso2');
            if ($raw !== null && $raw !== '') {
                $iso2 = strtoupper(trim((string) $raw));
            }
        }
        $monthNum = (int) $now->format('n');

        return [
            'country' => trim((string) (Setting::getValue('country', '') ?? '')),
            'country_code' => $iso2,
            'week' => sprintf('%02d', (int) $now->isoWeek()),
            'quarter' => (string) (int) ceil($monthNum / 3),
            'hub_brand' => trim((string) (Setting::getValue('hub_brand_name', '') ?? '')),
        ];
    }

    /**
     * @param  array<string, string|int>  $replacements  clés sans accolades
     */
    public static function apply(string $template, array $replacements): string
    {
        $out = $template;
        foreach ($replacements as $key => $value) {
            $out = str_replace('{'.$key.'}', (string) $value, $out);
        }

        return $out;
    }
}
