<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facture — {{ $shipment->public_tracking }}</title>
    <style>
        @page { margin: 20px; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.3; color: #000; margin: 0; padding: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        td { vertical-align: top; }

        .header-top { margin-bottom: 20px; }
        .logo-cell { width: 30%; }
        .company-info-cell { width: 40%; text-align: center; font-size: 10px; }
        .qr-cell { width: 30%; text-align: right; vertical-align: top; }
        .qr-img { width: 100px; height: 100px; }
        .tracking-under-qr { font-size: 11px; font-weight: bold; margin-top: 4px; text-align: right; }

        .info-section { margin-bottom: 15px; }
        .facturer-title { font-weight: bold; font-size: 12px; margin-bottom: 5px; }

        .logistics-table td { border: 1px solid #000; padding: 4px 8px; }
        .logistics-table .label-cell { background-color: #f1f5f9; font-weight: bold; width: 35%; }
        .logistics-table .value-cell { width: 65%; }

        .items-table th { border: 1px solid #000; background-color: #64748b; color: #fff; padding: 6px; font-size: 9px; text-align: center; }
        .items-table td { border: 1px solid #000; padding: 6px; text-align: center; font-size: 10px; }
        .items-table .text-left { text-align: left; }

        .value-split-table td { border: 1px solid #000; padding: 6px 8px; }
        .value-split-table .v-label { background-color: #e2e8f0; font-weight: bold; width: 55%; }
        .value-split-table .v-num { text-align: right; font-weight: 600; }

        .pricing-grid { margin-top: 5px; }
        .pricing-grid td { border: 1px solid #000; padding: 4px; text-align: center; }
        .pricing-grid .label-row { background-color: #64748b; color: #fff; font-weight: bold; font-size: 10px; }

        .terms-section { margin-top: 20px; border-top: 1px solid #000; padding-top: 10px; margin-bottom: 48px; }
        .terms-title { font-weight: bold; text-align: center; letter-spacing: 5px; margin-bottom: 8px; text-transform: uppercase; }
        .terms-text { font-size: 9px; text-align: justify; line-height: 1.45; }

        .signatures { margin-top: 130px; width: 100%; }
        .sig-box { width: 42%; vertical-align: bottom; }
        .sig-inner { border-top: 1px solid #000; padding-top: 10px; min-height: 56px; text-align: center; }
        .sig-label { font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.35; display: block; margin-bottom: 28px; }

        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
@php
    $sp = $shipment->senderProfile;
    $rp = $shipment->recipientProfile;
    $cur = $shipment->currency ?? $doc['currency'] ?? 'USD';
    $snap = $shipment->pricing_snapshot ?? [];

    $recipName = $rp?->full_name ?? '—';
    $recipAddress = implode(', ', array_filter([$rp?->address, $rp?->city?->name]));
    $recipCountry = $rp?->country?->name ?? '';
    $recipCity = $rp?->city?->name ?? '';
    $recipPhone = $rp?->phone ?? '';
    $recipEmail = $rp?->email ?? '';

    $tracking = $shipment->public_tracking ?? '—';
    $volDiv = isset($volumetric_divisor) ? (float) $volumetric_divisor : max(0.0001, (float) ($doc['volumetric_divisor'] ?? 5000));
    $invoiceNo = $document_invoice_number ?? $tracking;

    $discPct = (float) ($snap['discount_pct'] ?? $snap['discount_percentage'] ?? 0);
    $insPct = (float) ($snap['insurance_pct'] ?? $snap['insurance_percentage'] ?? 0);
    $custPct = (float) ($snap['customs_duty_pct'] ?? $snap['customs_duty_percentage'] ?? 0);
    $taxPct = (float) ($snap['tax_pct'] ?? $snap['tax_percentage'] ?? 0);

    $termsDefault = 'ACCEPTÉ : L\'expéditeur déclare ne pas envoyer d\'argent, d\'explosifs, d\'armes, de bijoux ni de produits chimiques. En cas de saisie douanière, les taxes seront à la charge du client. L\'entreprise pourra prendre en charge la marchandise dans la limite du montant indiqué sur la facture, selon l\'évaluation et les critères établis. L\'entreprise décline toute responsabilité en cas de casse ou de dommage. Le client autorise l\'agent à examiner visuellement le contenu du colis.';
    $termsRaw = trim((string) ($doc['invoice_terms'] ?? ''));
    $sigCompany = trim((string) ($doc['signing_company'] ?? ''));
    $sigCustomer = trim((string) ($doc['signing_customer'] ?? ''));
@endphp

    <table class="header-top">
        <tr>
            <td class="logo-cell">
                @if(!empty($doc['logo_data_uri']))
                    <img src="{{ $doc['logo_data_uri'] }}" alt="Logo" height="40">
                @elseif(!empty($doc['logo_url']))
                    <img src="{{ $doc['logo_url'] }}" alt="Logo" height="40">
                @else
                    <div style="font-size: 20px; font-weight: 800; color: #2563eb;">{{ $doc['site_name'] ?? 'MONRESPRO' }}</div>
                @endif
            </td>
            <td class="company-info-cell">
                @if(!empty($doc['nit']))TIN: {{ $doc['nit'] }}<br>@endif
                @if(!empty($doc['phone']))Téléphone: {{ $doc['phone'] }}<br>@endif
                @if(!empty($doc['site_email']))E-mail: {{ $doc['site_email'] }}<br>@endif
                @if(!empty($doc['address'])){{ $doc['address'] }}@if(!empty($doc['city'])), {{ $doc['city'] }}@endif @if(!empty($doc['country'])), {{ $doc['country'] }}@endif @endif
            </td>
            <td class="qr-cell">
                @if(!empty($tracking_qr_data_uri))
                    <img src="{{ $tracking_qr_data_uri }}" class="qr-img" alt="QR suivi">
                    <div class="tracking-under-qr">Suivi : {{ $tracking }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="info-section">
        <tr>
            <td style="width: 55%; padding-right: 20px;">
                <div class="facturer-title">Facturer à</div>
                <div style="font-weight: bold; margin-bottom: 5px;">{{ $recipName }}</div>
                <div>{{ $recipAddress }}</div>
                <div>{{ $recipCountry }} | {{ $recipCity }}</div>
                <div>{{ $recipPhone }}</div>
                <div>{{ $recipEmail }}</div>
            </td>
            <td style="width: 45%;">
                <table class="logistics-table">
                    <tr><td class="label-cell">Mode d'expédition</td><td class="value-cell">{{ $logistics['shippingMode'] ?? '—' }}</td></tr>
                    <tr><td class="label-cell">Compagnie de courrier</td><td class="value-cell">{{ $logistics['transport'] ?? '—' }}</td></tr>
                    <tr><td class="label-cell">Date d'enregistrement</td><td class="value-cell">{{ $shipment->created_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                    <tr><td class="label-cell">Opérateur</td><td class="value-cell">{{ $shipment->creator?->name ?? '—' }}</td></tr>
                    <tr><td class="label-cell">Numéro de facture</td><td class="value-cell">{{ $invoiceNo }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 22%;">Description</th>
                <th style="width: 9%;">Poids (kg)</th>
                <th style="width: 7%;">Qté</th>
                <th style="width: 7%;">L (cm)</th>
                <th style="width: 7%;">l (cm)</th>
                <th style="width: 7%;">H (cm)</th>
                <th style="width: 10%;">Poids vol.</th>
                <th style="width: 14%;">Montant déclaré</th>
            </tr>
        </thead>
        <tbody>
            @foreach($shipment->items as $item)
            @php
                $qty = max(1, (int) ($item->quantity ?? 1));
                $lineDeclared = (float) ($item->value ?? 0) * $qty;
            @endphp
            <tr>
                <td class="text-left">{{ $item->description }}</td>
                <td>{{ $item->weight_kg }}</td>
                <td>{{ $qty }}</td>
                <td>{{ $item->length_cm ?? 0 }}</td>
                <td>{{ $item->width_cm ?? 0 }}</td>
                <td>{{ $item->height_cm ?? 0 }}</td>
                <td>{{ (($__l = (float) ($item->length_cm ?? 0)) > 0 && ($__w = (float) ($item->width_cm ?? 0)) > 0 && ($__h = (float) ($item->height_cm ?? 0)) > 0) ? round(($__l * $__w * $__h) / $volDiv, 2) : 0 }}</td>
                <td>{{ number_format($lineDeclared, 2) }} {{ $cur }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: -5px;">
        <tr>
            <td style="width: 45%;">
                <table class="logistics-table">
                    <tr>
                        <td class="label-cell" style="width: 40%;">Prix kg : {{ number_format((float) ($invoice_price_per_kg ?? 0), 2) }}</td>
                        <td class="value-cell">Poids : {{ $metrics['sum_weight'] }}</td>
                    </tr>
                    <tr>
                        <td class="label-cell">Poids volumétrique : {{ $metrics['sum_volumetric'] }}</td>
                        <td class="value-cell">Poids facturable : {{ $metrics['billing_weight'] }}</td>
                    </tr>
                </table>
            </td>
            <td style="width: 15%;"></td>
            <td style="width: 40%;">
                <table class="logistics-table">
                    <tr>
                        <td class="label-cell" style="text-align: center; width: 80%;">Sous-total transport</td>
                        <td class="value-cell" style="text-align: center;">{{ number_format((float)($snap['subtotal'] ?? 0), 2) }} {{ $cur }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="value-split-table" style="margin-top: 8px;">
        <tr>
            <td class="v-label">Valeur déclarée par le client (réf.)</td>
            <td class="v-num">{{ number_format((float) ($invoice_client_declared ?? 0), 2) }} {{ $cur }}</td>
        </tr>
        <tr>
            <td class="v-label">Montant de prise en charge par l'entreprise (plafond indicatif)</td>
            <td class="v-num">
                @if(isset($invoice_company_coverage) && $invoice_company_coverage !== null)
                    {{ number_format((float) $invoice_company_coverage, 2) }} {{ $cur }}
                @else
                    —
                @endif
            </td>
        </tr>
    </table>

    <table class="pricing-grid">
        <tr class="label-row">
            <td>Remise {{ number_format($discPct, 0) }}%</td>
            <td>Assurance {{ number_format($insPct, 0) }}%</td>
            <td>Droits de douane {{ number_format($custPct, 0) }}%</td>
            <td>Taxe {{ number_format($taxPct, 0) }}%</td>
            <td>Total expédition</td>
        </tr>
        <tr>
            <td>{{ number_format((float)($snap['discount_amount'] ?? 0), 2) }}</td>
            <td>{{ number_format((float)($snap['insurance_amount'] ?? 0), 2) }}</td>
            <td>{{ number_format((float)($snap['customs_duty_amount'] ?? 0), 2) }}</td>
            <td>{{ number_format((float)($snap['tax_amount'] ?? 0), 2) }}</td>
            <td style="font-weight: bold;">{{ number_format((float)($snap['total'] ?? 0), 2) }} {{ $cur }}</td>
        </tr>
    </table>

    <div class="terms-section">
        <div class="terms-title">Termes</div>
        <div class="terms-text">
            @if($termsRaw !== '')
                {!! nl2br(e($termsRaw)) !!}
            @else
                {{ $termsDefault }}
            @endif
        </div>
    </div>

    <table class="signatures">
        <tr>
            <td class="sig-box">
                <div class="sig-inner">
                    <span class="sig-label">{{ $sigCompany !== '' ? $sigCompany : "SIGNATURE DE L'ENTREPRISE" }}</span>
                </div>
            </td>
            <td style="width: 12%;"></td>
            <td class="sig-box">
                <div class="sig-inner">
                    <span class="sig-label">{{ $sigCustomer !== '' ? $sigCustomer : 'SIGNATURE DU CLIENT' }}</span>
                </div>
            </td>
        </tr>
    </table>

</body>
</html>
