<?php

namespace App\Listeners;

use App\Enums\ShipmentStatus;
use App\Events\ShipmentStatusChanged;
use App\Models\Invoice;
use App\Models\Setting;
use App\Support\ReferenceNumberFormatter;
use App\Support\SequenceSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * §9.8 PRD — Création automatique d'une facture (brouillon / en attente) à la livraison si absente.
 */
class CreateInvoiceOnShipmentDelivered
{
    public function handle(ShipmentStatusChanged $event): void
    {
        if ($event->newStatus !== ShipmentStatus::Delivered) {
            return;
        }

        $shipment = $event->shipment->fresh(['senderProfile.user', 'creator']);

        if (Invoice::query()->where('shipment_id', $shipment->id)->exists()) {
            return;
        }

        $amount = (float) ($shipment->calculated_price ?? 0);
        if ($amount <= 0) {
            return;
        }

        $billUserId = $shipment->creator_user_id ?? $shipment->senderProfile?->user?->id;
        if (! $billUserId) {
            return;
        }

        $currency = $shipment->currency ?? $shipment->declared_currency ?? 'USD';

        try {
            DB::transaction(function () use ($shipment, $amount, $currency, $billUserId) {
                $format = trim((string) (Setting::getValue('finance_invoice_format', '{prefix}-{seq}') ?? ''));
                if ($format === '') {
                    $format = '{prefix}-{seq}';
                }
                $prefix = trim((string) (Setting::getValue('finance_invoice_prefix', 'INV') ?? 'INV')) ?: 'INV';
                $pad = max(1, min(12, (int) (Setting::getValue('finance_invoice_seq_pad', '6') ?: 6)));

                do {
                    $seq = SequenceSetting::allocateNext('finance_invoice_next_seq', 1);
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
                        ],
                    ));
                } while (Invoice::query()->where('invoice_number', $number)->exists());

                Invoice::query()->create([
                    'invoice_number' => $number,
                    'user_id' => (int) $billUserId,
                    'shipment_id' => $shipment->id,
                    'amount' => round($amount, 2),
                    'base_amount' => null,
                    'currency' => $currency,
                    'status' => 'pending',
                    'due_at' => now()->addDays(30),
                    'paid_at' => null,
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('CreateInvoiceOnShipmentDelivered failed: '.$e->getMessage(), [
                'shipment_id' => $shipment->id,
            ]);
        }
    }
}
