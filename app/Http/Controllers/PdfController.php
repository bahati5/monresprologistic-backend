<?php

namespace App\Http\Controllers;

use App\Models\DeliveryTime;
use App\Models\Invoice;
use App\Models\PreAlert;
use App\Models\Shipment;
use App\Models\ShippingMode;
use App\Support\QrCodeHelper;
use App\Support\ShipmentDocumentSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PdfController extends Controller
{
    /**
     * Digital HTML preview of the shipment label.
     */
    public function previewShipmentLabel(Shipment $shipment): JsonResponse
    {
        $this->authorize('view', $shipment);

        $data = $this->shipmentLabelViewData($shipment, true);
        $html = view('pdf.shipment-label', $data)->render();

        return response()->json([
            'html' => $html,
            'shipment_id' => $shipment->id,
            'tracking' => $shipment->public_tracking,
        ]);
    }

    /**
     * Digital HTML preview of the shipment invoice.
     */
    public function previewShipmentInvoice(Shipment $shipment): JsonResponse
    {
        $this->authorize('view', $shipment);

        $data = $this->shipmentInvoiceViewData($shipment, true);
        $html = view('pdf.shipment-invoice', $data)->render();

        return response()->json([
            'html' => $html,
            'shipment_id' => $shipment->id,
            'tracking' => $shipment->public_tracking,
        ]);
    }

    public function shipmentInvoice(Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);

        $pdf = Pdf::loadView('pdf.shipment-invoice', $this->shipmentInvoiceViewData($shipment, false));
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("facture-expedition-{$shipment->public_tracking}.pdf");
    }

    public function shipmentLabel(Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);

        $pdf = Pdf::loadView('pdf.shipment-label', $this->shipmentLabelViewData($shipment, false));
        $this->enableRemoteAssets($pdf);
        // 10×15 cm = 283×425 pt
        $pdf->setPaper([0, 0, 283, 425]);

        return $pdf->stream("etiquette-{$shipment->public_tracking}.pdf");
    }

    public function packageInvoice(PreAlert $preAlert): Response
    {
        $preAlert->load(['user', 'locker']);

        $pdf = Pdf::loadView('pdf.package-invoice', [
            'package' => $preAlert,
            'settings' => \App\Models\Setting::all()->pluck('value', 'key'),
        ]);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("facture-colis-{$preAlert->id}.pdf");
    }

    public function consolidationDocument($consolidation): Response
    {
        $consolidation->load(['user', 'agency', 'shipments', 'preAlerts']);

        $pdf = Pdf::loadView('pdf.consolidation', [
            'consolidation' => $consolidation,
            'settings' => \App\Models\Setting::all()->pluck('value', 'key'),
        ]);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("bon-consolidation-{$consolidation->master_tracking}.pdf");
    }

    public function trackingReport(Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);

        $shipment->load(['statusLogs', 'sender', 'recipient']);

        $pdf = Pdf::loadView('pdf.tracking-report', [
            'shipment' => $shipment,
            'logs' => $shipment->statusLogs ?? collect(),
        ]);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("suivi-{$shipment->public_tracking}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentInvoiceViewData(Shipment $shipment, bool $preview): array
    {
        $shipment->load(['sender.locker', 'senderClient', 'senderProfile', 'recipient', 'deliveryRecipient', 'recipientProfile', 'agency', 'status', 'items.originCountry', 'invoices', 'serviceType']);

        $doc = ShipmentDocumentSettings::merged();
        $invoice = $shipment->invoices->first();
        $metrics = $this->lineMetrics($shipment);
        $logistics = $this->logisticsForPdf($shipment, $doc);

        $tracking = (string) ($shipment->public_tracking ?? '');

        return [
            'shipment' => $shipment,
            'invoice' => $invoice,
            'doc' => $doc,
            'metrics' => $metrics,
            'logistics' => $logistics,
            'preview' => $preview,
            'tracking_qr_data_uri' => QrCodeHelper::trackingDataUri($tracking, 120),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentLabelViewData(Shipment $shipment, bool $preview): array
    {
        $shipment->load(['sender.locker', 'senderClient', 'senderProfile', 'recipient', 'deliveryRecipient', 'recipientProfile', 'agency', 'status', 'items.originCountry', 'invoices', 'serviceType']);

        $doc = ShipmentDocumentSettings::merged();
        $invoice = $shipment->invoices->first();
        $metrics = $this->lineMetrics($shipment);
        $logistics = $this->logisticsForPdf($shipment, $doc);
        $tracking = (string) ($shipment->public_tracking ?? '');

        return [
            'shipment' => $shipment,
            'invoice' => $invoice,
            'doc' => $doc,
            'metrics' => $metrics,
            'logistics' => $logistics,
            'tracking_qr_data_uri' => QrCodeHelper::trackingDataUri($tracking, 220),
            'invoice_paid' => $this->invoiceIsPaid($invoice),
            'preview' => $preview,
        ];
    }

    /**
     * Libellés logistiques issus de service_options (assistant) avec repli sur les paramètres document.
     *
     * @param  array<string, mixed>  $doc
     * @return array{shippingMode: string, deliveryTime: string, transport: string, shipLine: string}
     */
    private function logisticsForPdf(Shipment $shipment, array $doc): array
    {
        $opts = $shipment->service_options ?? [];

        $shippingMode = '—';
        if (! empty($opts['shipping_mode_id'])) {
            $m = ShippingMode::query()->find((int) $opts['shipping_mode_id']);
            $n = $m?->name;
            if (is_array($n)) {
                $shippingMode = (string) ($n['fr'] ?? $n['en'] ?? reset($n) ?? '—');
            } elseif ($n !== null && $n !== '') {
                $shippingMode = (string) $n;
            }
        }
        if ($shippingMode === '—' && ! empty($doc['shipping_mode_label'])) {
            $shippingMode = (string) $doc['shipping_mode_label'];
        }

        $deliveryTime = '—';
        if (! empty($opts['delivery_time_id'])) {
            $dt = DeliveryTime::query()->find((int) $opts['delivery_time_id']);
            $lb = $dt?->label;
            if (is_array($lb)) {
                $deliveryTime = (string) ($lb['fr'] ?? $lb['en'] ?? reset($lb) ?? '—');
            } elseif ($lb !== null && $lb !== '') {
                $deliveryTime = (string) $lb;
            }
        }

        $transport = (string) ($opts['transport_company_name'] ?? $doc['transport_company'] ?? '—');
        $shipLine = (string) ($opts['ship_line_name'] ?? '—');

        return [
            'shippingMode' => $shippingMode,
            'deliveryTime' => $deliveryTime,
            'transport' => $transport,
            'shipLine' => $shipLine,
        ];
    }

    /**
     * @return array{item_count:int, sum_weight:float, sum_volumetric:float, billing_weight:float, sum_length:float, sum_width:float, sum_height:float}
     */
    private function lineMetrics(Shipment $shipment): array
    {
        $divisor = max(0.0001, (float) config('shipment_documents.volumetric_divisor', 5000));
        $items = $shipment->items;

        $sumWeight = 0.0;
        $sumVolumetric = 0.0;
        $sumLength = 0.0;
        $sumWidth = 0.0;
        $sumHeight = 0.0;
        $itemCount = 0;

        foreach ($items as $it) {
            $qty = max(1, (int) $it->quantity);
            $w = (float) ($it->weight_kg ?? 0);
            $l = (float) ($it->length_cm ?? 0);
            $wi = (float) ($it->width_cm ?? 0);
            $h = (float) ($it->height_cm ?? 0);
            $sumWeight += $w * $qty;
            $volKg = ($l * $wi * $h) / $divisor;
            $sumVolumetric += $volKg * $qty;
            $sumLength += $l * $qty;
            $sumWidth += $wi * $qty;
            $sumHeight += $h * $qty;
            $itemCount += $qty;
        }

        if ($items->isEmpty()) {
            $sumWeight = (float) ($shipment->weight_kg ?? 0);
            $sumVolumetric = (float) ($shipment->volumetric_weight_kg ?? 0);
            $sumLength = (float) ($shipment->length_cm ?? 0);
            $sumWidth = (float) ($shipment->width_cm ?? 0);
            $sumHeight = (float) ($shipment->height_cm ?? 0);
            $itemCount = 1;
        }

        return [
            'item_count' => max(1, $itemCount),
            'sum_weight' => round($sumWeight, 3),
            'sum_volumetric' => round($sumVolumetric, 3),
            'billing_weight' => round(max($sumWeight, $sumVolumetric), 3),
            'sum_length' => round($sumLength, 2),
            'sum_width' => round($sumWidth, 2),
            'sum_height' => round($sumHeight, 2),
        ];
    }

    private function invoiceIsPaid(?Invoice $invoice): bool
    {
        if (! $invoice) {
            return false;
        }

        return $invoice->paid_at !== null || $invoice->status === 'paid';
    }

    private function enableRemoteAssets(\Barryvdh\DomPDF\PDF $pdf): void
    {
        $pdf->getDomPDF()->getOptions()->set('isRemoteEnabled', true);
    }
}
