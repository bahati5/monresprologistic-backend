<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\Setting;
use App\Services\OdooShipmentInvoiceSyncService;
use App\Services\Integrations\OdooService;

class InvoiceObserver
{
    public function updated(Invoice $invoice): void
    {
        if (! app(OdooService::class)->isEnabled()) {
            return;
        }

        $trigger = Setting::getValue('odoo_invoice_sync_trigger');
        $trigger = ($trigger !== null && trim((string) $trigger) !== '') ? trim((string) $trigger) : 'on_delivered';

        if ($trigger !== 'on_invoice_accounting') {
            return;
        }

        if (! $invoice->wasChanged('status')) {
            return;
        }

        if (! in_array($invoice->status, ['paid', 'sent', 'partial'], true)) {
            return;
        }

        if ($invoice->odoo_exported_at !== null) {
            return;
        }

        $shipment = $invoice->shipment;
        if (! $shipment) {
            return;
        }

        app(OdooShipmentInvoiceSyncService::class)->pushForShipmentInvoice($shipment->fresh(), $invoice->fresh());
    }
}
