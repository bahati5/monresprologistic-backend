<?php

namespace App\Listeners;

use App\Enums\RefundStatus;
use App\Enums\ShipmentStatus;
use App\Events\RefundStatusChanged;
use App\Events\ShipmentStatusChanged;
use App\Models\Invoice;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\SyncError;
use App\Services\Integrations\OdooService;
use App\Services\OdooShipmentInvoiceSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * §16 — Synchronise les événements métier (factures, paiements, avoirs) vers Odoo ERP.
 */
class SyncOdooListener implements ShouldQueue
{
    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(
        private OdooService $odoo,
        private OdooShipmentInvoiceSyncService $shipmentInvoiceSync,
    ) {}

    /**
     * §16.1 — Expédition livrée → créer la facture dans Odoo et enregistrer le paiement si réglée.
     */
    public function handleShipmentStatusChanged(ShipmentStatusChanged $event): void
    {
        if (! $this->odoo->isEnabled()) {
            return;
        }

        if ($event->newStatus !== ShipmentStatus::Delivered) {
            return;
        }

        $trigger = Setting::getValue('odoo_invoice_sync_trigger');
        $trigger = ($trigger !== null && trim((string) $trigger) !== '') ? trim((string) $trigger) : 'on_delivered';
        if ($trigger !== 'on_delivered') {
            return;
        }

        $shipment = $event->shipment->fresh();

        $invoice = Invoice::query()
            ->where('shipment_id', $shipment->id)
            ->first();

        if (! $invoice) {
            return;
        }

        $this->shipmentInvoiceSync->pushForShipmentInvoice($shipment, $invoice->fresh(), $this->tries, $this->backoff[0] ?? 30);
    }

    /**
     * §16.2 — Remboursement traité → créer un avoir client dans Odoo.
     */
    public function handleRefundStatusChanged(RefundStatusChanged $event): void
    {
        if (! $this->odoo->isEnabled()) {
            return;
        }

        $refund = $event->refund;

        if ($event->newStatus === RefundStatus::Processed->value) {
            try {
                $this->odoo->createCreditNote([
                    'move_type' => 'out_refund',
                    'ref' => $refund->reference_code,
                    'partner_id' => false,
                    'invoice_line_ids' => [[
                        0, 0, [
                            'name' => "Remboursement {$refund->reference_code} — {$refund->reason}",
                            'price_unit' => (float) $refund->amount,
                            'quantity' => 1,
                        ],
                    ]],
                ]);
            } catch (\Throwable $e) {
                SyncError::query()->create([
                    'integration' => 'odoo',
                    'event_type' => 'refund_credit_note',
                    'entity_type' => Refund::class,
                    'entity_id' => (int) $refund->id,
                    'payload' => ['reference_code' => $refund->reference_code],
                    'error_message' => $e->getMessage(),
                    'stack_trace' => $e->getTraceAsString(),
                    'attempt' => 1,
                    'max_attempts' => $this->tries,
                    'resolved' => false,
                    'next_retry_at' => now()->addSeconds($this->backoff[0] ?? 30),
                ]);
                Log::warning("Odoo credit note failed for refund #{$refund->id}: {$e->getMessage()}");
            }
        }
    }
}
