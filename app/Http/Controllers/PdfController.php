<?php

namespace App\Http\Controllers;

use App\Models\PreAlert;
use App\Models\Regroupement;
use App\Models\Setting;
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
        $data['doc'] = $this->withAbsoluteLogoUrlForHtmlPreview($data['doc'] ?? []);
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
        $data['doc'] = $this->withAbsoluteLogoUrlForHtmlPreview($data['doc'] ?? []);
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

        $data = $this->shipmentInvoiceViewData($shipment, false);
        $data['doc'] = $this->docForDomPdfLogo($data['doc'] ?? []);

        $pdf = Pdf::loadView('pdf.shipment-invoice', $data);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("facture-expedition-{$shipment->public_tracking}.pdf");
    }

    public function shipmentLabel(Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);

        $data = $this->shipmentLabelViewData($shipment, false);
        $data['doc'] = $this->docForDomPdfLogo($data['doc'] ?? []);

        $pdf = Pdf::loadView('pdf.shipment-label', $data);
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
            'settings' => Setting::all()->pluck('value', 'key'),
        ]);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("facture-colis-{$preAlert->id}.pdf");
    }

    public function regroupementDocument(Regroupement $regroupement): Response
    {
        $regroupement->load(['agency', 'shipments.senderProfile', 'shipments.recipientProfile']);

        $pdf = Pdf::loadView('pdf.regroupement', [
            'regroupement' => $regroupement,
            'settings' => Setting::all()->pluck('value', 'key'),
        ]);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("bon-regroupement-{$regroupement->batch_number}.pdf");
    }

    public function trackingReport(Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);

        $shipment->load(['logs' => fn ($q) => $q->with('user')->orderByDesc('created_at'), 'senderProfile', 'recipientProfile']);

        $pdf = Pdf::loadView('pdf.tracking-report', [
            'shipment' => $shipment,
            'logs' => $shipment->logs,
        ]);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("suivi-{$shipment->public_tracking}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentInvoiceViewData(Shipment $shipment, bool $preview): array
    {
        $shipment->load(['senderProfile', 'senderProfile.country', 'senderProfile.city', 'recipientProfile', 'recipientProfile.country', 'recipientProfile.city', 'agency', 'status', 'items.originCountry', 'invoices.extraLines', 'creator.locker', 'originCountry', 'destCountry']);

        $doc = ShipmentDocumentSettings::merged();
        $invoice = $shipment->invoices->first();
        $metrics = $this->lineMetrics($shipment);
        $logistics = $this->logisticsForPdf($shipment, $doc);

        $tracking = (string) ($shipment->public_tracking ?? '');
        $volDiv = max(0.0001, (float) ($doc['volumetric_divisor'] ?? 5000));
        $docInvoice = trim((string) ($shipment->invoice_document_number ?? ''));
        if ($docInvoice === '' && $invoice) {
            $docInvoice = (string) ($invoice->invoice_number ?? '');
        }
        if ($docInvoice === '') {
            $docInvoice = $tracking;
        }

        $snap = is_array($shipment->pricing_snapshot) ? $shipment->pricing_snapshot : [];
        $itemsDeclaredSum = $shipment->items->sum(fn ($it) => (float) ($it->value ?? 0) * max(1, (int) ($it->quantity ?? 1)));
        $declaredOnShipment = (float) ($shipment->declared_value ?? 0);
        $fromSnapDeclared = (float) ($snap['declared_value_effective'] ?? 0);
        $invoiceClientDeclared = $declaredOnShipment > 0 ? $declaredOnShipment : ($fromSnapDeclared > 0 ? $fromSnapDeclared : $itemsDeclaredSum);

        $billingW = (float) ($metrics['billing_weight'] ?? 0);
        $baseQuote = (float) ($snap['base_quote'] ?? 0);
        $storedPpk = (float) ($snap['price_per_kg'] ?? 0);
        if ($storedPpk > 0) {
            $invoicePricePerKg = $storedPpk;
        } elseif ($billingW > 0.00001 && $baseQuote > 0) {
            $invoicePricePerKg = round($baseQuote / $billingW, 4);
        } else {
            $invoicePricePerKg = 0.0;
        }

        $coverage = $shipment->company_coverage_amount;
        if ($coverage === null) {
            $defCov = trim((string) Setting::getValue('default_company_coverage_amount', ''));
            $invoiceCompanyCoverage = ($defCov !== '' && is_numeric($defCov)) ? (float) $defCov : null;
        } else {
            $invoiceCompanyCoverage = (float) $coverage;
        }

        return [
            'shipment' => $shipment,
            'invoice' => $invoice,
            'doc' => $doc,
            'metrics' => $metrics,
            'logistics' => $logistics,
            'preview' => $preview,
            'volumetric_divisor' => $volDiv,
            'document_invoice_number' => $docInvoice,
            'tracking_qr_data_uri' => QrCodeHelper::trackingDataUri($tracking, 120),
            'tracking_barcode_data_uri' => QrCodeHelper::barcodeDataUri($tracking),
            'invoice_price_per_kg' => $invoicePricePerKg,
            'invoice_client_declared' => $invoiceClientDeclared,
            'invoice_company_coverage' => $invoiceCompanyCoverage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentLabelViewData(Shipment $shipment, bool $preview): array
    {
        $shipment->load(['senderProfile', 'senderProfile.country', 'senderProfile.city', 'recipientProfile', 'recipientProfile.country', 'recipientProfile.city', 'agency', 'status', 'items.originCountry', 'invoices.extraLines', 'creator.locker', 'originCountry', 'destCountry']);

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
        if (! empty($opts['delivery_time_label'])) {
            $deliveryTime = trim((string) $opts['delivery_time_label']) ?: '—';
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

    private function enableRemoteAssets(\Barryvdh\DomPDF\PDF $pdf): void
    {
        $pdf->getDomPDF()->getOptions()->set('isRemoteEnabled', true);
    }

    /**
     * Pour DomPDF : n’utiliser que le logo embarqué (data URI). Une URL /storage/… en PNG
     * provoquerait encore un chargement PNG (GD requis). Le data URI est produit par
     * ShipmentDocumentSettings::logoFileDataUri (JPEG/SVG ou PNG si GD, sinon Imagick→JPEG).
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function docForDomPdfLogo(array $doc): array
    {
        $doc['logo_url'] = null;

        return $doc;
    }

    /**
     * Les iframes (srcDoc) ne résolvent pas les URLs relatives /storage/... : forcer une URL absolue pour le logo.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function withAbsoluteLogoUrlForHtmlPreview(array $doc): array
    {
        if (! empty($doc['logo_data_uri'])) {
            return $doc;
        }
        $url = $doc['logo_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return $doc;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, 'data:')) {
            return $doc;
        }
        $doc['logo_url'] = rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');

        return $doc;
    }
}
