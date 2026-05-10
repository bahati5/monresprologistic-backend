<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Formulaire d'expédition — {{ $shipment->public_tracking }}</title>
    <style>
        @page { margin: 20px; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.3; color: #000; margin: 0; padding: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        td { vertical-align: top; }

        .header-top { margin-bottom: 15px; }
        .logo-cell { width: 30%; }
        .title-cell { width: 40%; text-align: center; }
        .qr-cell { width: 30%; text-align: right; vertical-align: top; }
        .qr-img { width: 110px; height: 110px; }
        .tracking-under-qr { font-size: 11px; font-weight: bold; margin-top: 4px; text-align: right; }

        .doc-title { font-size: 16px; font-weight: 900; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 4px; }
        .doc-subtitle { font-size: 10px; color: #475569; }

        .section-title { font-weight: bold; font-size: 12px; margin: 14px 0 6px 0; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid #1e293b; padding-bottom: 3px; color: #1e293b; }

        .info-table td { border: 1px solid #cbd5e1; padding: 4px 8px; }
        .info-table .label-cell { background-color: #f1f5f9; font-weight: bold; width: 25%; font-size: 10px; }
        .info-table .value-cell { width: 75%; }

        .items-table th { border: 1px solid #000; background-color: #1e293b; color: #fff; padding: 6px; font-size: 9px; text-align: center; }
        .items-table td { border: 1px solid #000; padding: 6px; text-align: center; font-size: 10px; }
        .items-table .text-left { text-align: left; }
        .items-table .totals-row { background-color: #f1f5f9; font-weight: bold; }

        .shipping-table td { border: 1px solid #cbd5e1; padding: 5px 8px; }
        .shipping-table .label-cell { background-color: #f1f5f9; font-weight: bold; width: 35%; }

        .declaration-section { margin-top: 18px; border: 2px solid #1e293b; padding: 12px; }
        .declaration-title { font-weight: bold; text-align: center; letter-spacing: 3px; margin-bottom: 8px; text-transform: uppercase; font-size: 11px; }
        .declaration-text { font-size: 9px; text-align: justify; line-height: 1.45; }

        .signatures { margin-top: 60px; width: 100%; }
        .sig-box { width: 42%; vertical-align: bottom; }
        .sig-inner { border-top: 2px solid #000; padding-top: 8px; min-height: 56px; text-align: center; }
        .sig-label { font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.35; display: block; margin-bottom: 28px; }
        .sig-date { font-size: 9px; color: #64748b; margin-top: 4px; }

        .meta-footer { margin-top: 20px; text-align: center; font-size: 8px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 6px; }

        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
@php
    $sp = $shipment->senderProfile;
    $rp = $shipment->recipientProfile;
    $tracking = $shipment->public_tracking ?? '—';

    $senderName = $sp?->full_name ?? '—';
    $senderAddress = implode(', ', array_filter([$sp?->address, $sp?->landmark]));
    $senderCity = $sp?->city?->name ?? '';
    $senderState = $sp?->state?->name ?? '';
    $senderZip = $sp?->zip_code ?? '';
    $senderCountry = $sp?->country?->name ?? '';
    if (is_array($senderCountry)) $senderCountry = $senderCountry['fr'] ?? $senderCountry['en'] ?? reset($senderCountry) ?? '';
    $senderPhone = $sp?->phone ?? '';
    $senderPhoneAlt = $sp?->phone_secondary ?? '';
    $senderEmail = $sp?->email ?? '';

    $recipName = $rp?->full_name ?? '—';
    $recipAddress = implode(', ', array_filter([$rp?->address, $rp?->landmark]));
    $recipCity = $rp?->city?->name ?? '';
    $recipState = $rp?->state?->name ?? '';
    $recipZip = $rp?->zip_code ?? '';
    $recipCountry = $rp?->country?->name ?? '';
    if (is_array($recipCountry)) $recipCountry = $recipCountry['fr'] ?? $recipCountry['en'] ?? reset($recipCountry) ?? '';
    $recipPhone = $rp?->phone ?? '';
    $recipPhoneAlt = $rp?->phone_secondary ?? '';
    $recipEmail = $rp?->email ?? '';

    $cur = $shipment->currency ?? $doc['currency'] ?? 'USD';
    $volDiv = max(0.0001, (float) ($doc['volumetric_divisor'] ?? 5000));
    $nbColis = max(1, (int) ($shipment->items->sum(fn($i) => max(1, (int)($i->quantity ?? 1)))));

    $termsDefault = "ACCEPTÉ : L'expéditeur déclare que les informations ci-dessus sont exactes et complètes. L'expéditeur déclare ne pas envoyer d'argent, d'explosifs, d'armes, de bijoux ni de produits chimiques. En cas de saisie douanière, les taxes seront à la charge du client. L'entreprise décline toute responsabilité en cas de casse ou de dommage. Le client autorise l'agent à examiner visuellement le contenu du colis.";
    $termsRaw = trim((string) ($doc['form_terms'] ?? $doc['invoice_terms'] ?? ''));
    $sigCompany = trim((string) ($doc['signing_company'] ?? ''));
    $sigCustomer = trim((string) ($doc['signing_customer'] ?? ''));
@endphp

    {{-- Header --}}
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
            <td class="title-cell">
                <div class="doc-title">Formulaire d'expédition</div>
                <div class="doc-subtitle">
                    @if(!empty($doc['phone']))Tél: {{ $doc['phone'] }}@endif
                    @if(!empty($doc['site_email'])) | {{ $doc['site_email'] }}@endif
                </div>
            </td>
            <td class="qr-cell">
                @if(!empty($tracking_qr_data_uri))
                    <img src="{{ $tracking_qr_data_uri }}" class="qr-img" alt="QR suivi">
                @endif
                <div class="tracking-under-qr">{{ $tracking }}</div>
                @if(!empty($tracking_barcode_data_uri))
                    <div style="margin-top: 4px;"><img src="{{ $tracking_barcode_data_uri }}" alt="Code-barres" style="height: 28px;"></div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Reference --}}
    <table class="shipping-table" style="margin-bottom: 14px;">
        <tr>
            <td class="label-cell">N° de suivi</td>
            <td style="font-weight: bold; font-size: 13px;">{{ $tracking }}</td>
            <td class="label-cell">Date</td>
            <td>{{ $shipment->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
            <td class="label-cell">Opérateur</td>
            <td>{{ $shipment->creator?->name ?? '—' }}</td>
        </tr>
    </table>

    {{-- Expediteur --}}
    <div class="section-title">Expéditeur</div>
    <table class="info-table">
        <tr><td class="label-cell">Nom complet</td><td class="value-cell" colspan="3">{{ $senderName }}</td></tr>
        <tr>
            <td class="label-cell">Téléphone</td><td class="value-cell">{{ $senderPhone }}@if($senderPhoneAlt) / {{ $senderPhoneAlt }}@endif</td>
            <td class="label-cell" style="width: 15%;">Email</td><td class="value-cell">{{ $senderEmail }}</td>
        </tr>
        <tr>
            <td class="label-cell">Adresse</td><td class="value-cell" colspan="3">{{ $senderAddress ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label-cell">Ville</td><td class="value-cell">{{ $senderCity ?: '—' }}@if($senderState), {{ $senderState }}@endif @if($senderZip)— {{ $senderZip }}@endif</td>
            <td class="label-cell" style="width: 15%;">Pays</td><td class="value-cell">{{ $senderCountry ?: '—' }}</td>
        </tr>
    </table>

    {{-- Destinataire --}}
    <div class="section-title">Destinataire</div>
    <table class="info-table">
        <tr><td class="label-cell">Nom complet</td><td class="value-cell" colspan="3">{{ $recipName }}</td></tr>
        <tr>
            <td class="label-cell">Téléphone</td><td class="value-cell">{{ $recipPhone }}@if($recipPhoneAlt) / {{ $recipPhoneAlt }}@endif</td>
            <td class="label-cell" style="width: 15%;">Email</td><td class="value-cell">{{ $recipEmail }}</td>
        </tr>
        <tr>
            <td class="label-cell">Adresse</td><td class="value-cell" colspan="3">{{ $recipAddress ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label-cell">Ville</td><td class="value-cell">{{ $recipCity ?: '—' }}@if($recipState), {{ $recipState }}@endif @if($recipZip)— {{ $recipZip }}@endif</td>
            <td class="label-cell" style="width: 15%;">Pays</td><td class="value-cell">{{ $recipCountry ?: '—' }}</td>
        </tr>
    </table>

    {{-- Contenu du colis --}}
    <div class="section-title">Contenu du colis ({{ $nbColis }} article{{ $nbColis > 1 ? 's' : '' }})</div>
    @php
        $totalWeight = 0; $totalDeclared = 0; $totalQty = 0;
    @endphp
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30%;">Description</th>
                <th style="width: 10%;">Pays orig.</th>
                <th style="width: 8%;">Qté</th>
                <th style="width: 10%;">Poids (kg)</th>
                <th style="width: 8%;">L (cm)</th>
                <th style="width: 8%;">l (cm)</th>
                <th style="width: 8%;">H (cm)</th>
                <th style="width: 18%;">Valeur déclarée</th>
            </tr>
        </thead>
        <tbody>
            @foreach($shipment->items as $item)
            @php
                $qty = max(1, (int) ($item->quantity ?? 1));
                $w = (float) ($item->weight_kg ?? 0);
                $val = (float) ($item->value ?? 0) * $qty;
                $totalWeight += $w * $qty;
                $totalDeclared += $val;
                $totalQty += $qty;
                $originName = $item->originCountry?->name ?? '';
                if (is_array($originName)) $originName = $originName['fr'] ?? $originName['en'] ?? reset($originName) ?? '';
            @endphp
            <tr>
                <td class="text-left">{{ $item->description }}</td>
                <td>{{ $originName ?: '—' }}</td>
                <td>{{ $qty }}</td>
                <td>{{ number_format($w, 2) }}</td>
                <td>{{ $item->length_cm ?? 0 }}</td>
                <td>{{ $item->width_cm ?? 0 }}</td>
                <td>{{ $item->height_cm ?? 0 }}</td>
                <td>{{ number_format($val, 2) }} {{ $cur }}</td>
            </tr>
            @endforeach
            <tr class="totals-row">
                <td class="text-left">TOTAL</td>
                <td></td>
                <td>{{ $totalQty }}</td>
                <td>{{ number_format($totalWeight, 2) }}</td>
                <td colspan="3"></td>
                <td>{{ number_format($totalDeclared, 2) }} {{ $cur }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Informations d'envoi --}}
    <div class="section-title">Informations d'envoi</div>
    <table class="shipping-table">
        <tr>
            <td class="label-cell">Mode de transport</td>
            <td>{{ $logistics['shippingMode'] ?? '—' }}</td>
            <td class="label-cell">Compagnie</td>
            <td>{{ $logistics['transport'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label-cell">Délai estimé</td>
            <td>{{ $logistics['deliveryTime'] ?? '—' }}</td>
            <td class="label-cell">Nombre de colis</td>
            <td>{{ $nbColis }}</td>
        </tr>
    </table>

    {{-- Declaration --}}
    <div class="declaration-section">
        <div class="declaration-title">Déclaration</div>
        <div class="declaration-text">
            @if($termsRaw !== '')
                {!! nl2br(e($termsRaw)) !!}
            @else
                {{ $termsDefault }}
            @endif
        </div>
    </div>

    {{-- Signatures --}}
    <table class="signatures">
        <tr>
            <td class="sig-box">
                <div class="sig-inner">
                    <span class="sig-label">{{ $sigCompany !== '' ? $sigCompany : "SIGNATURE DE L'ENTREPRISE" }}</span>
                </div>
                <div class="sig-date">Date : ____/____/________</div>
            </td>
            <td style="width: 12%;"></td>
            <td class="sig-box">
                <div class="sig-inner">
                    <span class="sig-label">{{ $sigCustomer !== '' ? $sigCustomer : "SIGNATURE DU CLIENT" }}</span>
                    <div style="font-size: 8px; color: #64748b; font-style: italic;">« Je déclare que les informations ci-dessus sont exactes. »</div>
                </div>
                <div class="sig-date">Date : ____/____/________</div>
            </td>
        </tr>
    </table>

    <div class="meta-footer">
        Document généré le {{ now()->format('d/m/Y à H:i') }} — {{ $doc['site_name'] ?? 'Monrespro' }} — {{ $tracking }}
    </div>
</body>
</html>
