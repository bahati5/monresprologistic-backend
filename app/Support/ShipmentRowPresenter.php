<?php

namespace App\Support;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Services\ShipmentWorkflowService;

class ShipmentRowPresenter
{
    /**
     * @param  mixed  $value
     */
    public static function localeLabel($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $t = trim($value);

            return $t !== '' ? $t : null;
        }
        if (is_array($value)) {
            $pick = $value['fr'] ?? $value['en'] ?? reset($value);

            return self::localeLabel($pick);
        }

        return null;
    }

    /**
     * @return array{origin_country: ?string, origin_city: ?string, origin_iso2: ?string, dest_country: ?string, dest_city: ?string, dest_iso2: ?string}
     */
    public static function corridor(Shipment $s): array
    {
        $rp = $s->recipientProfile;
        $sp = $s->senderProfile;
        $oc = $s->originCountry;
        $dc = $s->destCountry;
        $originCountryLabel = self::localeLabel($oc?->name) ?? self::localeLabel($sp?->country?->name);
        $destCountryLabel = self::localeLabel($dc?->name) ?? self::localeLabel($rp?->country?->name);
        $originIso = strtoupper(trim((string) ($oc?->iso2
            ?? $sp?->country?->iso2
            ?? '')));
        $destIso = strtoupper(trim((string) ($dc?->iso2
            ?? $rp?->country?->iso2
            ?? '')));

        return [
            'origin_country' => $originCountryLabel,
            'origin_city' => self::localeLabel($sp?->city?->name),
            'origin_iso2' => $originIso !== '' ? $originIso : null,
            'dest_country' => $destCountryLabel,
            'dest_city' => self::localeLabel($rp?->city?->name),
            'dest_iso2' => $destIso !== '' ? $destIso : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $corridor
     */
    public static function routeDisplay(array $corridor): ?string
    {
        $o = (string) ($corridor['origin_country'] ?? '');
        $d = (string) ($corridor['dest_country'] ?? '');
        if ($o !== '' && $d !== '') {
            return $o.' → '.$d;
        }

        $one = $o !== '' ? $o : $d;

        return $one !== '' ? $one : null;
    }

    /**
     * Ligne synthétique pour sous-tableau « lot » (regroupement) ou liste compacte.
     *
     * @return array<string, mixed>
     */
    public static function summaryForRegroupement(Shipment $s): array
    {
        $workflowSvc = app(ShipmentWorkflowService::class);
        $st = $s->status ?? ShipmentStatus::Draft;
        $corridor = self::corridor($s);

        return [
            'id' => $s->id,
            'public_tracking' => $s->public_tracking,
            'tracking_number' => $s->public_tracking,
            'recipient_name' => $s->recipientProfile?->full_name,
            'weight_kg' => $s->weight_kg !== null ? (float) $s->weight_kg : null,
            'status' => [
                'code' => $st->value,
                'name' => $st->label(),
                'color_hex' => $workflowSvc->colorHexForStatus($st),
            ],
            'corridor' => $corridor,
            'route_display' => self::routeDisplay($corridor),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $summaries
     * @return array{origin_iso2s: array<int, string>, dest_iso2s: array<int, string>, origin_countries: array<int, string>, dest_countries: array<int, string>, label: ?string}
     */
    public static function aggregateLotRoute(array $summaries): array
    {
        $rows = collect($summaries);
        $originIso2s = $rows->pluck('corridor.origin_iso2')->filter()->unique()->values()->all();
        $destIso2s = $rows->pluck('corridor.dest_iso2')->filter()->unique()->values()->all();
        $originCountries = $rows->pluck('corridor.origin_country')->filter()->unique()->values()->all();
        $destCountries = $rows->pluck('corridor.dest_country')->filter()->unique()->values()->all();

        $label = null;
        if (count($originCountries) === 1 && count($destCountries) === 1) {
            $label = $originCountries[0].' → '.$destCountries[0];
        } elseif (count($originCountries) > 0 && count($destCountries) > 0) {
            $label = implode(' · ', $originCountries).' → '.implode(' · ', $destCountries);
        } elseif (count($originCountries) > 0) {
            $label = implode(' · ', $originCountries);
        } elseif (count($destCountries) > 0) {
            $label = implode(' · ', $destCountries);
        }

        return [
            'origin_iso2s' => $originIso2s,
            'dest_iso2s' => $destIso2s,
            'origin_countries' => $originCountries,
            'dest_countries' => $destCountries,
            'label' => $label,
        ];
    }
}
