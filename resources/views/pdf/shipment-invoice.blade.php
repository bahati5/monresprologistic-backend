<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facture — {{ $shipment->public_tracking }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.5; color: #1e293b; padding: 30px; }
        .header-table { width: 100%; margin-bottom: 20px; }
        .header-table td { vertical-align: top; }
        .brand { font-size: 22px; font-weight: 800; color: #2563eb; letter-spacing: -0.5px; }
        .brand-sub { font-size: 9px; color: #64748b; margin-top: 2px; }
        .doc-title { font-size: 18px; font-weight: 700; color: #2563eb; text-align: right; text-transform: uppercase; }
        .doc-meta { font-size: 10px; color: #64748b; text-align: right; margin-top: 4px; }
        .accent-bar { height: 3px; background: linear-gradient(90deg, #2563eb, #60a5fa); margin-bottom: 20px; border-radius: 2px; }
        .two-col { width: 100%; margin-bottom: 15px; }
        .two-col td { width: 50%; vertical-align: top; padding: 0; }
        .two-col td:first-child { padding-right: 8px; }
        .two-col td:last-child { padding-left: 8px; }
        .info-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; }
        .info-title { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.5px; margin-bottom: 5px; }
        .info-name { font-size: 13px; font-weight: 700; color: #0f172a; }
        .info-detail { font-size: 10px; color: #475569; margin-top: 2px; }
        .section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin: 18px 0 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        table.items th { background: #f1f5f9; font-size: 9px; font-weight: 700; text-transform: uppercase; color: #64748b; padding: 6px 8px; text-align: left; border-bottom: 2px solid #e2e8f0; }
        table.items td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; font-size: 10px; }
        table.items tr:last-child td { border-bottom: none; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .totals-table { width: 260px; margin-left: auto; margin-top: 10px; }
        .totals-table td { padding: 4px 8px; font-size: 10px; }
        .totals-table .label { color: #64748b; }
        .totals-table .value { text-align: right; font-weight: 600; }
        .totals-table .grand { font-size: 14px; font-weight: 800; color: #2563eb; border-top: 2px solid #2563eb; padding-top: 8px; }
        .totals-table .grand .label { color: #2563eb; font-size: 14px; font-weight: 800; }
        .tracking-strip { background: #eff6ff; border: 2px solid #2563eb; border-radius: 6px; padding: 10px 14px; text-align: center; margin-bottom: 18px; }
        .tracking-label { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #64748b; }
        .tracking-code { font-family: 'Courier New', Courier, monospace; font-size: 20px; font-weight: 800; color: #2563eb; letter-spacing: 2px; }
        .tracking-qr img { display: block; width: 88px; height: 88px; }
        .payment-box { border-radius: 6px; padding: 10px 14px; margin-top: 15px; }
        .payment-paid { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .payment-unpaid { background: #fef2f2; border: 1px solid #fecaca; }
        .payment-partial { background: #fffbeb; border: 1px solid #fde68a; }
        .footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 9px; color: #94a3b8; }
        .logistics-grid { width: 100%; margin-bottom: 8px; }
        .logistics-grid td { padding: 3px 8px; font-size: 10px; vertical-align: top; }
        .logistics-grid .lbl { color: #64748b; font-weight: 600; width: 40%; }
        .logistics-grid .val { color: #1e293b; }
        @if(!empty($preview))
        .no-print { display: block; }
        @else
        .no-print { display: none; }
        @endif
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
@php
    $sender = $shipment->sender;
    $senderClient = $shipment->senderClient;
    $recipient = $shipment->recipient;
    $deliveryRecipient = $shipment->deliveryRecipient ?? null;
    $cur = $shipment->currency ?? $doc['currency'] ?? 'USD';
    $snap = $shipment->pricing_snapshot ?? [];
    $opts = $shipment->service_options ?? [];
    $manualFeeLabel = trim((string) ($opts['manual_fee_label'] ?? ''));
    $supplementLineLabel = $manualFeeLabel === '' ? 'Supplément' : 'Supplément '.$manualFeeLabel;

    $senderName = $senderClient->company_name ?? $senderClient->name ?? $sender?->name ?? '—';
    $senderEmail = $senderClient->email ?? $sender?->email ?? '';
    $senderPhone = $senderClient->phone ?? $sender?->phone ?? '';
    $senderLocker = $sender?->locker?->code ?? null;

    $recipName = $deliveryRecipient->name ?? $recipient?->name ?? '—';
    $recipEmail = $deliveryRecipient->email ?? $recipient?->email ?? '';
    $recipPhone = $deliveryRecipient->phone ?? $recipient?->phone ?? '';
    $recipCity = $deliveryRecipient->city ?? '';
    $recipCountry = $deliveryRecipient->country ?? '';

    $payStatus = $shipment->payment_status ?? 'unpaid';
    $amountPaid = (float) ($shipment->amount_paid ?? 0);
@endphp

    {{-- HEADER --}}
    <table class="header-table">
        <tr>
            <td style="width: 55%;">
                @if(!empty($doc['logo_data_uri']))
                    <img src="{{ $doc['logo_data_uri'] }}" alt="{{ $doc['site_name'] }}" height="{{ $doc['logo_thumb_h'] }}" style="margin-bottom: 4px;"><br>
                @elseif(!empty($doc['logo_url']))
                    <img src="{{ $doc['logo_url'] }}" alt="{{ $doc['site_name'] }}" height="{{ $doc['logo_thumb_h'] }}" style="margin-bottom: 4px;"><br>
                @else
                    <div class="brand">{{ $doc['site_name'] ?? 'MONRESPRO' }}</div>
                @endif
                <div class="brand-sub">
                    {{ $doc['address'] ?? '' }}@if(!empty($doc['city'])), {{ $doc['city'] }}@endif @if(!empty($doc['country'])), {{ $doc['country'] }}@endif<br>
                    @if(!empty($doc['phone']))Tél : {{ $doc['phone'] }} | @endif{{ $doc['site_email'] ?? '' }}
                    @if(!empty($doc['nit']))<br>NIT : {{ $doc['nit'] }}@endif
                </div>
            </td>
            <td style="width: 45%; text-align: right;">
                <div class="doc-title">Facture d'expédition</div>
                <div class="doc-meta">
                    @if($invoice)N° {{ $invoice->invoice_number }}<br>@endif
                    Date : {{ $shipment->created_at?->format('d/m/Y') ?? '—' }}<br>
                    @if($invoice?->due_at)Échéance : {{ $invoice->due_at->format('d/m/Y') }}@endif
                </div>
                @if(!empty($tracking_qr_data_uri))
                <div class="tracking-qr" style="margin-top:8px; display:inline-block;">
                    <img src="{{ $tracking_qr_data_uri }}" alt="QR suivi" width="88" height="88">
                </div>
                @endif
            </td>
        </tr>
    </table>

    <div class="accent-bar"></div>

    {{-- TRACKING STRIP --}}
    <div class="tracking-strip">
        <div class="tracking-label">Numéro de suivi</div>
        <div class="tracking-code">{{ $shipment->public_tracking ?? '—' }}</div>
    </div>

    {{-- SENDER / RECIPIENT --}}
    <table class="two-col">
        <tr>
            <td>
                <div class="info-card">
                    <div class="info-title">Expéditeur</div>
                    <div class="info-name">{{ $senderName }}</div>
                    <div class="info-detail">{{ $senderEmail }}</div>
                    @if($senderPhone)<div class="info-detail">{{ $senderPhone }}</div>@endif
                    @if($senderLocker)<div class="info-detail" style="margin-top:3px; font-weight:700; color:#2563eb;">Casier : {{ $senderLocker }}</div>@endif
                </div>
            </td>
            <td>
                <div class="info-card">
                    <div class="info-title">Destinataire</div>
                    <div class="info-name">{{ $recipName }}</div>
                    <div class="info-detail">{{ $recipEmail }}</div>
                    @if($recipPhone)<div class="info-detail">{{ $recipPhone }}</div>@endif
                    @if($recipCity || $recipCountry)
                        <div class="info-detail">{{ $recipCity }}@if($recipCity && $recipCountry), @endif{{ $recipCountry }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    {{-- LOGISTICS INFO --}}
    <div class="section-title">Informations logistiques</div>
    <table class="logistics-grid">
        <tr>
            <td class="lbl">Agence :</td>
            <td class="val">{{ $shipment->agency?->name ?? '—' }}</td>
            <td class="lbl">Mode d'expédition :</td>
            <td class="val">{{ $logistics['shippingMode'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Délai :</td>
            <td class="val">{{ $logistics['deliveryTime'] ?? '—' }}</td>
            <td class="lbl">Ligne :</td>
            <td class="val">{{ $logistics['shipLine'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Compagnie de transport :</td>
            <td class="val">{{ $logistics['transport'] ?? '—' }}</td>
            <td class="lbl">Type de service :</td>
            <td class="val">{{ is_array($shipment->serviceType?->name) ? ($shipment->serviceType->name['fr'] ?? reset($shipment->serviceType->name)) : ($shipment->serviceType?->name ?? '—') }}</td>
        </tr>
        <tr>
            <td class="lbl">Poids réel :</td>
            <td class="val">{{ $metrics['sum_weight'] }} {{ $doc['weight_unit'] ?? 'kg' }}</td>
            <td class="lbl">Poids volumétrique :</td>
            <td class="val">{{ $metrics['sum_volumetric'] }} {{ $doc['weight_unit'] ?? 'kg' }}</td>
        </tr>
        <tr>
            <td class="lbl">Poids facturable :</td>
            <td class="val" style="font-weight:700;">{{ $metrics['billing_weight'] }} {{ $doc['weight_unit'] ?? 'kg' }}</td>
            <td class="lbl">Nombre de colis :</td>
            <td class="val">{{ $metrics['item_count'] }}</td>
        </tr>
        @if(!empty($shipment->declared_value))
        <tr>
            <td class="lbl">Valeur déclarée :</td>
            <td class="val">{{ number_format((float) $shipment->declared_value, 2) }} {{ $shipment->declared_currency ?? 'USD' }}</td>
            <td class="lbl">Dimensions (L×l×h) :</td>
            <td class="val">{{ $metrics['sum_length'] }} × {{ $metrics['sum_width'] }} × {{ $metrics['sum_height'] }} cm</td>
        </tr>
        @endif
    </table>

    {{-- ARTICLES --}}
    <div class="section-title">Détail des articles</div>
    <table class="items">
        <thead>
            <tr>
                <th style="width:4%;">#</th>
                <th style="width:28%;">Désignation</th>
                <th style="width:14%;">Origine</th>
                <th class="text-center" style="width:7%;">Qté</th>
                <th class="text-right" style="width:12%;">Poids (kg)</th>
                <th class="text-right" style="width:15%;">Dimensions (cm)</th>
                <th class="text-right" style="width:20%;">Valeur</th>
            </tr>
        </thead>
        <tbody>
            @forelse($shipment->items as $idx => $item)
            @php
                $oc = $item->originCountry;
                $originStr = $oc ? trim(($oc->name ?? '').($oc->iso2 ? ' ('.$oc->iso2.')' : '')) : '';
            @endphp
            <tr>
                <td>{{ $idx + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td>{{ $originStr !== '' ? $originStr : '—' }}</td>
                <td class="text-center">{{ $item->quantity }}</td>
                <td class="text-right">{{ $item->weight_kg ? number_format((float) $item->weight_kg, 2) : '—' }}</td>
                <td class="text-right">
                    @if($item->length_cm){{ $item->length_cm }}×{{ $item->width_cm }}×{{ $item->height_cm }}@else — @endif
                </td>
                <td class="text-right">
                    @if($item->value){{ number_format((float) $item->value, 2) }} {{ $cur }}@else — @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center" style="color:#94a3b8;">Aucun article détaillé</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- PRICING BREAKDOWN --}}
    <div class="section-title">Facturation</div>
    <table class="totals-table">
        @if(!empty($snap['base_quote']))
        <tr><td class="label">Devis de base</td><td class="value">{{ number_format((float) $snap['base_quote'], 2) }} {{ $cur }}</td></tr>
        @endif
        @if(!empty($snap['manual_fee']) && (float)$snap['manual_fee'] > 0)
        <tr><td class="label">{{ $supplementLineLabel }}</td><td class="value">{{ number_format((float) $snap['manual_fee'], 2) }} {{ $cur }}</td></tr>
        @endif
        @if(!empty($snap['fixed_fees']) && (float)$snap['fixed_fees'] > 0)
        <tr><td class="label">Frais fixes</td><td class="value">{{ number_format((float) $snap['fixed_fees'], 2) }} {{ $cur }}</td></tr>
        @endif
        @if(!empty($snap['insurance_amount']) && (float)$snap['insurance_amount'] > 0)
        <tr><td class="label">Assurance</td><td class="value">{{ number_format((float) $snap['insurance_amount'], 2) }} {{ $cur }}</td></tr>
        @endif
        @if(!empty($snap['customs_duty_amount']) && (float)$snap['customs_duty_amount'] > 0)
        <tr><td class="label">Droits de douane</td><td class="value">{{ number_format((float) $snap['customs_duty_amount'], 2) }} {{ $cur }}</td></tr>
        @endif
        @if(!empty($snap['packaging_fee']) && (float)$snap['packaging_fee'] > 0)
        @php
            $pq = (int) ($snap['packaging_quantity'] ?? 0);
            $pu = isset($snap['packaging_unit_price']) ? (float) $snap['packaging_unit_price'] : 0.0;
            $pl = $snap['packaging_label'] ?? '';
        @endphp
        <tr><td class="label">Emballage @if($pl !== '')({{ $pl }})@endif @if($pq > 0 && $pu > 0)<span style="color:#64748b;font-size:10px;"> — {{ $pq }} × {{ number_format($pu, 2) }} {{ $cur }}</span>@endif</td><td class="value">{{ number_format((float) $snap['packaging_fee'], 2) }} {{ $cur }}</td></tr>
        @endif
        @if(!empty($snap['subtotal']))
        <tr><td class="label" style="border-top:1px solid #e2e8f0; padding-top:6px;">Sous-total</td><td class="value" style="border-top:1px solid #e2e8f0; padding-top:6px;">{{ number_format((float) $snap['subtotal'], 2) }} {{ $cur }}</td></tr>
        @endif
        @if(!empty($snap['tax_amount']) && (float)$snap['tax_amount'] > 0)
        <tr><td class="label">Taxes</td><td class="value">+ {{ number_format((float) $snap['tax_amount'], 2) }} {{ $cur }}</td></tr>
        @endif
        @if(!empty($snap['discount_amount']) && (float)$snap['discount_amount'] > 0)
        <tr><td class="label">Remise</td><td class="value" style="color:#16a34a;">- {{ number_format((float) $snap['discount_amount'], 2) }} {{ $cur }}</td></tr>
        @endif
        <tr class="grand">
            <td class="label">TOTAL</td>
            <td class="value" style="font-size:14px;">{{ number_format((float) ($snap['total'] ?? $shipment->calculated_price ?? 0), 2) }} {{ $cur }}</td>
        </tr>
    </table>

    {{-- PAYMENT STATUS --}}
    @php $totalDue = (float) ($snap['total'] ?? $shipment->calculated_price ?? 0); @endphp
    <div class="payment-box {{ $payStatus === 'paid' ? 'payment-paid' : ($payStatus === 'partial' ? 'payment-partial' : 'payment-unpaid') }}">
        <table style="width:100%;">
            <tr>
                <td style="width:50%; vertical-align:top;">
                    <div class="info-title" style="margin-bottom:3px;">Statut du paiement</div>
                    <strong style="font-size:13px; color:{{ $payStatus === 'paid' ? '#16a34a' : ($payStatus === 'partial' ? '#d97706' : '#dc2626') }};">
                        {{ $payStatus === 'paid' ? '✓ PAYÉ' : ($payStatus === 'partial' ? '◐ PAIEMENT PARTIEL' : '✗ NON PAYÉ') }}
                    </strong>
                </td>
                <td style="width:50%; text-align:right; vertical-align:top;">
                    @if($amountPaid > 0)
                    <div style="font-size:10px; color:#64748b;">Montant payé : <strong>{{ number_format($amountPaid, 2) }} {{ $cur }}</strong></div>
                    @endif
                    @if($payStatus !== 'paid' && $totalDue > 0)
                    <div style="font-size:10px; color:#dc2626; margin-top:2px;">Reste à payer : <strong>{{ number_format(max(0, $totalDue - $amountPaid), 2) }} {{ $cur }}</strong></div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- TERMS --}}
    @if(!empty($doc['invoice_terms']))
    <div style="margin-top:18px; padding:8px 12px; background:#f8fafc; border-radius:4px; font-size:9px; color:#64748b;">
        <strong>Conditions :</strong> {{ $doc['invoice_terms'] }}
    </div>
    @endif

    {{-- SIGNATURES --}}
    <table style="width:100%; margin-top:25px;">
        <tr>
            <td style="width:50%; vertical-align:top;">
                <div style="font-size:10px; font-weight:600; color:#64748b;">{{ $doc['signing_company'] ?: 'Pour la société' }}</div>
                <div style="height:50px; border-bottom:1px dashed #cbd5e1; margin-top:5px;"></div>
            </td>
            <td style="width:50%; vertical-align:top; text-align:right;">
                <div style="font-size:10px; font-weight:600; color:#64748b;">{{ $doc['signing_customer'] ?: 'Le client' }}</div>
                <div style="height:50px; border-bottom:1px dashed #cbd5e1; margin-top:5px;"></div>
            </td>
        </tr>
    </table>

    {{-- FOOTER --}}
    <div class="footer">
        <p>Merci de votre confiance — {{ $doc['site_name'] ?? 'Monrespro' }}</p>
        <p>{{ $doc['site_email'] ?? '' }} @if(!empty($doc['phone']))| {{ $doc['phone'] }}@endif @if(!empty($doc['address']))| {{ $doc['address'] }}@endif</p>
    </div>

    @if(!empty($preview))
    <div class="no-print" style="text-align:center; margin-top:20px;">
        <button onclick="window.print()" style="padding:10px 24px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-size:14px; cursor:pointer;">
            Imprimer
        </button>
    </div>
    @endif
</body>
</html>
