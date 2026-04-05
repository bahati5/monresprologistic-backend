<?php

namespace App\Observers;

use App\Models\Setting;
use App\Models\Shipment;
use App\Support\ReferenceNumberFormatter;
use App\Support\SequenceSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShipmentObserver
{
    public function creating(Shipment $shipment): void
    {
        if ($shipment->public_tracking) {
            return;
        }

        $format = trim((string) (Setting::getValue('shipment_tracking_format', '{prefix}-{random}') ?? ''));
        if ($format === '') {
            $format = '{prefix}-{random}';
        }

        $prefix = trim((string) (Setting::getValue('tracking_prefix', 'MRP') ?? 'MRP')) ?: 'MRP';
        $randLen = (int) (Setting::getValue('tracking_number_length', '8') ?: 8);
        $randLen = max(4, min(32, $randLen));
        $seqPad = max(1, min(12, (int) (Setting::getValue('shipment_tracking_seq_pad', '6') ?: 6)));

        $usesSeq = str_contains($format, '{seq}');
        $now = now();

        $build = function (string $seqPadded, string $random) use ($format, $prefix, $now): string {
            return ReferenceNumberFormatter::apply($format, array_merge(
                ReferenceNumberFormatter::localeAndCalendarReplacements($now),
                [
                    'prefix' => $prefix,
                    'year' => $now->format('Y'),
                    'month' => $now->format('m'),
                    'day' => $now->format('d'),
                    'seq' => $seqPadded,
                    'random' => $random,
                ],
            ));
        };

        if ($usesSeq) {
            DB::transaction(function () use ($shipment, $seqPad, $randLen, $build): void {
                do {
                    $seqVal = SequenceSetting::allocateNext('shipment_tracking_next_seq', 1);
                    $seqPadded = str_pad((string) $seqVal, $seqPad, '0', STR_PAD_LEFT);
                    $random = strtoupper(Str::random($randLen));
                    $code = $build($seqPadded, $random);
                } while (Shipment::query()->where('public_tracking', $code)->exists());

                $shipment->public_tracking = $code;
            });

            return;
        }

        do {
            $random = strtoupper(Str::random($randLen));
            $code = $build('', $random);
        } while (Shipment::query()->where('public_tracking', $code)->exists());

        $shipment->public_tracking = $code;
    }
}
