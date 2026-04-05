<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

class ShipmentInvoiceNumberGenerator
{
    /**
     * Attribue un numéro de facture document (PDF) à l'expédition et incrémente le compteur.
     * Placeholders : {prefix}, {year}, {month}, {day}, {seq}, {id}, {country}, {country_code}, {week}, {quarter}, {hub_brand}
     */
    public static function assignToShipment(Shipment $shipment): void
    {
        if ($shipment->invoice_document_number) {
            return;
        }

        DB::transaction(function () use ($shipment) {
            $format = trim((string) (Setting::getValue('shipment_invoice_format', '{prefix}-{year}-{seq}') ?? ''));
            if ($format === '') {
                $format = '{prefix}-{year}-{seq}';
            }

            $prefix = trim((string) (Setting::getValue('shipment_invoice_prefix', 'FAC') ?? 'FAC'));
            $pad = max(1, min(12, (int) (Setting::getValue('shipment_invoice_seq_pad', '6') ?: 6)));

            $seq = SequenceSetting::allocateNext('shipment_invoice_next_seq', 1);
            $seqPadded = str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);

            $now = now();
            $number = ReferenceNumberFormatter::apply($format, array_merge(
                ReferenceNumberFormatter::localeAndCalendarReplacements($now),
                [
                    'prefix' => $prefix,
                    'year' => $now->format('Y'),
                    'month' => $now->format('m'),
                    'day' => $now->format('d'),
                    'seq' => $seqPadded,
                    'id' => (string) $shipment->id,
                ],
            ));

            $shipment->forceFill(['invoice_document_number' => $number])->save();
        });
    }
}
