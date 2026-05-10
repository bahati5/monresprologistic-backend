<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Shipment;
use App\Models\SyncError;
use App\Services\Integrations\OdooService;
use Illuminate\Support\Facades\Log;

/**
 * Export facture client liée à une expédition vers Odoo (évite duplication listener / facture payée).
 */
class OdooShipmentInvoiceSyncService
{
    public function __construct(private OdooService $odoo) {}

    public function pushForShipmentInvoice(Shipment $shipment, Invoice $invoice, int $maxAttempts = 3, int $firstBackoffSeconds = 30): void
    {
        if (! $this->odoo->isEnabled()) {
            return;
        }

        if ($invoice->odoo_exported_at !== null) {
            return;
        }

        try {
            $odooInvoiceId = $this->odoo->createInvoice([
                'move_type' => 'out_invoice',
                'ref' => $invoice->invoice_number,
                'invoice_date' => now()->toDateString(),
                'invoice_date_due' => $invoice->due_at?->toDateString() ?? now()->addDays(30)->toDateString(),
                'invoice_line_ids' => [[
                    0, 0, [
                        'name' => "Expédition {$shipment->public_tracking}",
                        'price_unit' => (float) $invoice->amount,
                        'quantity' => 1,
                    ],
                ]],
            ]);

            if ($odooInvoiceId && $invoice->status === 'paid') {
                $this->odoo->registerPayment([
                    'payment_type' => 'inbound',
                    'partner_type' => 'customer',
                    'amount' => (float) $invoice->amount,
                    'date' => $invoice->paid_at?->toDateString() ?? now()->toDateString(),
                    'memo' => $invoice->invoice_number,
                ]);
            }

            $invoice->forceFill(['odoo_exported_at' => now()])->save();
        } catch (\Throwable $e) {
            SyncError::query()->create([
                'integration' => 'odoo',
                'event_type' => 'shipment_delivered_invoice',
                'entity_type' => Invoice::class,
                'entity_id' => (int) $invoice->id,
                'payload' => ['shipment_id' => $shipment->id],
                'error_message' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString(),
                'attempt' => 1,
                'max_attempts' => $maxAttempts,
                'resolved' => false,
                'next_retry_at' => now()->addSeconds($firstBackoffSeconds),
            ]);
            Log::warning("Odoo invoice sync failed for shipment #{$shipment->id}: {$e->getMessage()}");
        }
    }
}
