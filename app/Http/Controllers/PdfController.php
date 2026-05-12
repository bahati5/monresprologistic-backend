<?php

namespace App\Http\Controllers;

use App\Models\AssistedPurchase;
use App\Models\Pickup;
use App\Models\PreAlert;
use App\Models\Regroupement;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Models\ShippingMode;
use App\Support\QrCodeHelper;
use App\Support\ShipmentDocumentSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PdfController extends Controller
{
    /**
     * Digital HTML preview of the shipment label.
     */
    public function previewShipmentLabel(Shipment $shipment): JsonResponse
    {
        $this->authorize('view', $shipment);
        $this->denyDriverCommercialPrinting();

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
        $this->denyDriverCommercialPrinting();

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
        $this->denyDriverCommercialPrinting();

        $data = $this->shipmentInvoiceViewData($shipment, false);
        $data['doc'] = $this->docForDomPdfLogo($data['doc'] ?? []);

        $pdf = Pdf::loadView('pdf.shipment-invoice', $data);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("facture-expedition-{$shipment->public_tracking}.pdf");
    }

    public function shipmentLabel(Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);
        $this->denyDriverCommercialPrinting();

        $data = $this->shipmentLabelViewData($shipment, false);
        $data['doc'] = $this->docForDomPdfLogo($data['doc'] ?? []);

        $pdf = Pdf::loadView('pdf.shipment-label', $data);
        $this->enableRemoteAssets($pdf);
        // 10×15 cm = 283×425 pt
        $pdf->setPaper([0, 0, 283, 425]);

        return $pdf->stream("etiquette-{$shipment->public_tracking}.pdf");
    }

    /**
     * Digital HTML preview of the shipment form (formulaire d'expédition — distinct from invoice).
     */
    public function previewShipmentForm(Shipment $shipment): JsonResponse
    {
        $this->authorize('view', $shipment);
        $this->denyDriverCommercialPrinting();

        $data = $this->shipmentFormViewData($shipment, true);
        $data['doc'] = $this->withAbsoluteLogoUrlForHtmlPreview($data['doc'] ?? []);
        $html = view('pdf.shipment-form', $data)->render();

        return response()->json([
            'html' => $html,
            'shipment_id' => $shipment->id,
            'tracking' => $shipment->public_tracking,
        ]);
    }

    public function shipmentForm(Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);
        $this->denyDriverCommercialPrinting();

        $data = $this->shipmentFormViewData($shipment, false);
        $data['doc'] = $this->docForDomPdfLogo($data['doc'] ?? []);

        $pdf = Pdf::loadView('pdf.shipment-form', $data);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("formulaire-expedition-{$shipment->public_tracking}.pdf");
    }

    public function packageInvoice(PreAlert $preAlert): Response
    {
        $this->denyDriverCommercialPrinting();
        $preAlert->load(['user', 'locker', 'user.locker']);

        $tracking = (string) ($preAlert->reference_code ?? '');
        $pdf = Pdf::loadView('pdf.package-invoice', [
            'package'         => $preAlert,
            'settings'        => Setting::all()->pluck('value', 'key'),
            'qr_data_uri'     => $tracking ? QrCodeHelper::trackingDataUri($tracking, 90) : null,
            'barcode_data_uri' => $tracking ? QrCodeHelper::barcodeDataUri($tracking) : null,
        ]);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("recu-depot-{$preAlert->id}.pdf");
    }

    public function regroupementDocument(Regroupement $regroupement): Response
    {
        $this->denyDriverCommercialPrinting();
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
        $this->denyDriverCommercialPrinting();

        $shipment->load([
            'logs' => fn ($q) => $q->with('user')->orderBy('created_at'),
            'senderProfile',
            'recipientProfile',
            'originCountry',
            'destCountry',
        ]);

        $doc = ShipmentDocumentSettings::merged();
        $doc = $this->docForDomPdfLogo($doc);

        $pdf = Pdf::loadView('pdf.tracking-report', [
            'shipment' => $shipment,
            'logs'     => $shipment->logs,
            'doc'      => $doc,
        ]);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("suivi-{$shipment->public_tracking}.pdf");
    }

    /**
     * Bon de livraison depuis une expédition (§12.2 PRD).
     */
    public function deliveryNote(Request $request, Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);

        $shipment->load([
            'senderProfile',
            'recipientProfile',
            'recipientProfile.country',
            'recipientProfile.city',
            'items',
            'originCountry',
            'destCountry',
            'creator',
        ]);

        $doc = ShipmentDocumentSettings::merged();
        $doc = $this->docForDomPdfLogo($doc);

        $pdf = Pdf::loadView('pdf.delivery-note', [
            'shipment' => $shipment,
            'pickup'   => null,
            'driver'   => $request->user(),
            'doc'      => $doc,
        ]);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("bon-livraison-{$shipment->public_tracking}.pdf");
    }

    /**
     * Bon de livraison depuis une tâche de ramassage/livraison (§12.2 PRD).
     */
    public function deliveryNotePickup(Request $request, Pickup $pickup): Response
    {
        $pickup->load(['driver', 'shipment.senderProfile', 'shipment.recipientProfile', 'shipment.items']);

        $doc = ShipmentDocumentSettings::merged();
        $doc = $this->docForDomPdfLogo($doc);

        $pdf = Pdf::loadView('pdf.delivery-note', [
            'shipment' => $pickup->shipment ?? null,
            'pickup'   => $pickup,
            'driver'   => $request->user(),
            'doc'      => $doc,
        ]);
        $this->enableRemoteAssets($pdf);

        return $pdf->stream("bon-tournee-{$pickup->id}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * §4 PRD — Les chauffeurs n’impriment pas factures / étiquettes / rapports commerciaux (bons de livraison OK).
     */
    private function denyDriverCommercialPrinting(): void
    {
        $u = auth()->user();
        if ($u instanceof User && $u->hasRole('driver')) {
            abort(403, 'Document réservé au personnel habilité.');
        }
    }

    private function shipmentInvoiceViewData(Shipment $shipment, bool $preview): array
    {
        $shipment->load(['senderProfile', 'senderProfile.country', 'senderProfile.city', 'recipientProfile', 'recipientProfile.country', 'recipientProfile.city', 'agency', 'items.originCountry', 'invoices.extraLines', 'creator.locker', 'originCountry', 'destCountry']);

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
        $shipment->load(['senderProfile', 'senderProfile.country', 'senderProfile.city', 'recipientProfile', 'recipientProfile.country', 'recipientProfile.city', 'agency', 'items.originCountry', 'invoices.extraLines', 'creator.locker', 'originCountry', 'destCountry']);

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
            'tracking_barcode_data_uri' => QrCodeHelper::barcodeDataUri($tracking),
            'preview' => $preview,
        ];
    }

    /**
     * Data for the standalone shipment form (formulaire d'expédition).
     */
    private function shipmentFormViewData(Shipment $shipment, bool $preview): array
    {
        $shipment->load([
            'senderProfile', 'senderProfile.country', 'senderProfile.city', 'senderProfile.state',
            'recipientProfile', 'recipientProfile.country', 'recipientProfile.city', 'recipientProfile.state',
            'agency', 'items.originCountry', 'creator', 'originCountry', 'destCountry',
        ]);

        $doc = ShipmentDocumentSettings::merged();
        $metrics = $this->lineMetrics($shipment);
        $logistics = $this->logisticsForPdf($shipment, $doc);
        $tracking = (string) ($shipment->public_tracking ?? '');

        return [
            'shipment' => $shipment,
            'doc' => $doc,
            'metrics' => $metrics,
            'logistics' => $logistics,
            'tracking_qr_data_uri' => QrCodeHelper::trackingDataUri($tracking, 120),
            'tracking_barcode_data_uri' => QrCodeHelper::barcodeDataUri($tracking),
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

    /**
     * §11 — PDF generation for assisted purchase quote.
     */
    public function assistedPurchaseQuote(AssistedPurchase $assisted_purchase): Response
    {
        $data = $this->assistedPurchaseQuoteData($assisted_purchase, false);
        $data['doc'] = $this->docForDomPdfLogo($data['doc'] ?? []);

        $pdf = Pdf::loadView('pdf.assisted-purchase-quote', $data);
        $this->enableRemoteAssets($pdf);

        $ref = str_pad((string) $assisted_purchase->id, 6, '0', STR_PAD_LEFT);

        return $pdf->stream("devis-achat-assiste-{$ref}.pdf");
    }

    /**
     * §11 — HTML preview for assisted purchase quote.
     */
    public function previewAssistedPurchaseQuote(AssistedPurchase $assisted_purchase): JsonResponse
    {
        $data = $this->assistedPurchaseQuoteData($assisted_purchase, true);
        $data['doc'] = $this->withAbsoluteLogoUrlForHtmlPreview($data['doc'] ?? []);
        $html = view('pdf.assisted-purchase-quote', $data)->render();

        return response()->json([
            'html' => $html,
            'purchase_id' => $assisted_purchase->id,
        ]);
    }

    /**
     * Build view data for assisted purchase quote PDF/preview.
     *
     * @return array<string, mixed>
     */
    private function assistedPurchaseQuoteData(AssistedPurchase $purchase, bool $isPreview): array
    {
        $purchase->loadMissing(['items', 'user']);
        $doc = ShipmentDocumentSettings::all();

        $snapshot = null;
        $latestSnapshot = $purchase->latestSnapshot;
        if ($latestSnapshot) {
            $snapshotData = is_string($latestSnapshot->snapshot_data)
                ? json_decode($latestSnapshot->snapshot_data, true)
                : $latestSnapshot->snapshot_data;

            $snapshot = array_merge($snapshotData ?? [], [
                'version' => $latestSnapshot->version,
                'total_primary' => (float) $latestSnapshot->total_primary,
                'total_secondary' => $latestSnapshot->total_secondary ? (float) $latestSnapshot->total_secondary : null,
                'secondary_currency' => $latestSnapshot->secondary_currency,
                'exchange_rate' => $latestSnapshot->exchange_rate_used ? (float) $latestSnapshot->exchange_rate_used : null,
                'is_urgent' => (bool) $latestSnapshot->is_urgent,
                'urgency_surcharge_percent' => $latestSnapshot->urgency_surcharge_percent,
                'estimated_delivery' => $latestSnapshot->estimated_delivery,
                'staff_message' => $latestSnapshot->staff_message,
                'expires_at' => $latestSnapshot->expires_at?->toIso8601String(),
            ]);
        }

        $user = $purchase->user;
        $lineNotes = json_decode($purchase->line_notes ?? '{}', true);

        $clientRows = [];
        $clientName = $user?->name ?? $lineNotes['full_name'] ?? '—';
        $clientRows[] = ['label' => 'Nom', 'value' => $clientName];
        $clientEmail = $user?->email ?? $lineNotes['email'] ?? null;
        if ($clientEmail) {
            $clientRows[] = ['label' => 'Email', 'value' => $clientEmail];
        }
        $clientPhone = $user?->phone ?? $lineNotes['phone'] ?? null;
        if ($clientPhone) {
            $clientRows[] = ['label' => 'Téléphone', 'value' => $clientPhone];
        }

        $quotedAt = $purchase->quoted_at ?? $purchase->created_at;
        $quotedAtFormatted = $quotedAt ? $quotedAt->format('d/m/Y') : now()->format('d/m/Y');

        $sym = trim((string) ($doc['currency_symbol'] ?? '$'));
        $pos = (string) (Setting::getValue('symbol_position', 'prefix') ?: 'prefix');
        $dec = max(0, min(6, (int) ($doc['decimals'] ?? 2)));

        $fmt = function (float $n) use ($sym, $pos, $dec): string {
            $num = number_format($n, $dec, ',', ' ');
            $sp = html_entity_decode('&nbsp;', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $pos === 'suffix' ? $num . $sp . $sym : $sym . $num;
        };

        $subtotal = 0;
        foreach ($purchase->items as $item) {
            $subtotal += (float) $item->unit_price * (int) $item->quantity;
        }

        $serviceFee = round($subtotal * 0.10, 2);
        $bankFee = round(($subtotal + $serviceFee) * 0.035, 2);
        $total = $subtotal + $serviceFee + $bankFee;

        $responseUrl = null;
        if ($latestSnapshot && $latestSnapshot->response_token) {
            $responseUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
                . '/quote-response?token=' . $latestSnapshot->response_token;
        }

        $qrData = rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/purchase-orders/' . $purchase->id;
        $qrDataUri = null;
        if (class_exists(QrCodeHelper::class)) {
            try {
                $qrDataUri = QrCodeHelper::toDataUri($qrData);
            } catch (\Throwable) {
            }
        }

        return [
            'purchase' => $purchase,
            'snapshot' => $snapshot,
            'quotedAtFormatted' => $quotedAtFormatted,
            'clientRows' => $clientRows,
            'responseUrl' => $responseUrl,
            'qr_data_uri' => $qrDataUri,
            'present' => [
                'doc' => $doc,
                'linesSubtotalFormatted' => $fmt($subtotal),
                'serviceFeeFormatted' => $fmt($serviceFee),
                'bankFeePercentageLabel' => '3.5%',
                'bankFeeFormatted' => $fmt($bankFee),
                'totalFormatted' => $fmt($total),
                'totalCdfFormatted' => null,
                'paymentMethodsNote' => null,
                'paymentUrl' => null,
            ],
            'doc' => $doc,
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
