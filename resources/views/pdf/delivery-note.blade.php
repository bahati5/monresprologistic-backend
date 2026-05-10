<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bon de livraison — {{ $shipment->public_tracking ?? ($pickup->id ?? '') }}</title>
    <style>
        @page { margin: 18px; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #000; margin: 0; padding: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        td { vertical-align: top; }
        .header-table td { border: none; }
        .info-table td { border: 1px solid #cbd5e1; padding: 5px 8px; }
        .info-table .label { background-color: #f8fafc; font-weight: bold; width: 35%; }
        .section-title { font-weight: bold; font-size: 12px; background-color: #064e3b; color: #fff; padding: 5px 10px; margin: 12px 0 4px 0; letter-spacing: 1px; text-transform: uppercase; }
        .badge { display: inline-block; padding: 3px 12px; border-radius: 3px; font-size: 11px; font-weight: bold; }
        .badge-livraison { background-color: #064e3b; color: #fff; }
        .badge-ramassage { background-color: #1e3a5f; color: #fff; }
        .ref-large { font-size: 22px; font-weight: 900; letter-spacing: 2px; text-align: center; margin: 8px 0 4px 0; }
        .qr-cell { text-align: center; }
        .qr-img { width: 100px; height: 100px; }
        .sig-table td { border: none; }
        .sig-box { border-top: 1px solid #000; padding-top: 8px; min-height: 70px; width: 44%; }
        .sig-label { font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 40px; }
        .instructions-box { background-color: #fefce8; border: 1px solid #fde047; padding: 8px 10px; margin: 8px 0; font-size: 10px; }
        .footer { margin-top: 16px; border-top: 1px solid #cbd5e1; padding-top: 6px; font-size: 9px; color: #94a3b8; text-align: center; }
        .check-row { display: flex; align-items: center; margin-bottom: 6px; font-size: 11px; }
        .check-box { border: 1px solid #000; width: 14px; height: 14px; display: inline-block; margin-right: 6px; }
    </style>
</head>
<body>
@php
    // Supports deux modes : livraison d'expédition ($shipment) ou tâche ramassage ($pickup)
    $tracking = $shipment?->public_tracking ?? null;
    $rp = $shipment?->recipientProfile ?? null;
    $sp = $shipment?->senderProfile ?? null;
    $isPickup = isset($pickup) && $pickup !== null;
    $isModePickup = ($pickup?->type ?? '') === 'pickup';
    $type = $isPickup ? ($isModePickup ? 'Ramassage' : 'Livraison') : 'Livraison';
    $badgeClass = $isModePickup ? 'badge-ramassage' : 'badge-livraison';

    $addressName   = $isPickup ? ($pickup->contact_name ?? '—') : ($rp?->full_name ?? '—');
    $addressLine   = $isPickup ? ($pickup->address ?? '—') : ($rp?->address ?? '—');
    $addressPhone  = $isPickup ? ($pickup->contact_phone ?? '—') : ($rp?->phone ?? '—');
    $instructions  = $isPickup ? ($pickup->notes ?? null) : ($shipment?->delivery_notes ?? null);
    $driverName    = $driver?->name ?? '—';
    $generatedAt   = now()->format('d/m/Y à H:i');
    $doc = $doc ?? [];
@endphp

{{-- En-tête --}}
<table class="header-table">
    <tr>
        <td style="width: 55%;">
            @if(!empty($doc['logo_data_uri']))
                <img src="{{ $doc['logo_data_uri'] }}" alt="Logo" height="36" style="margin-bottom: 4px;"><br>
            @elseif(!empty($doc['logo_url']))
                <img src="{{ $doc['logo_url'] }}" alt="Logo" height="36" style="margin-bottom: 4px;"><br>
            @else
                <div style="font-size: 18px; font-weight: 900; color: #064e3b;">{{ $doc['site_name'] ?? 'MONRESPRO' }}</div>
            @endif
            <div style="font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; margin-top: 2px;">Bon de {{ $type }}</div>
            <div style="margin-top: 6px;"><span class="badge {{ $badgeClass }}">{{ strtoupper($type) }}</span></div>
        </td>
        <td class="qr-cell" style="width: 45%;">
            @if($tracking)
                @php $qr = \App\Support\QrCodeHelper::trackingDataUri($tracking, 100); @endphp
                <img src="{{ $qr }}" class="qr-img" alt="QR suivi">
                <div style="font-size: 8px; margin-top: 2px;">{{ $tracking }}</div>
            @elseif(isset($pickup))
                <div style="font-size: 11px; font-weight: bold; color: #475569;">Tâche #{{ $pickup->id }}</div>
            @endif
        </td>
    </tr>
</table>

@if($tracking)
<div class="ref-large">{{ $tracking }}</div>
@php $bc = \App\Support\QrCodeHelper::barcodeDataUri($tracking); @endphp
@if(!empty($bc))
    <div style="text-align: center; margin: 2px 0 8px 0;">
        <img src="{{ $bc }}" style="height: 32px; max-width: 260px;" alt="Code-barres">
    </div>
@endif
@endif

{{-- Informations destinataire --}}
<div class="section-title">{{ $type === 'Ramassage' ? 'Point de collecte' : 'Destinataire' }}</div>
<table class="info-table">
    <tr>
        <td class="label">Nom</td>
        <td colspan="3">{{ $addressName }}</td>
    </tr>
    <tr>
        <td class="label">Adresse</td>
        <td colspan="3">{{ $addressLine }}</td>
    </tr>
    <tr>
        <td class="label">Téléphone</td>
        <td>{{ $addressPhone }}</td>
        <td class="label">Chauffeur</td>
        <td>{{ $driverName }}</td>
    </tr>
    @if($shipment?->created_at)
    <tr>
        <td class="label">Date d'expédition</td>
        <td>{{ $shipment->created_at->format('d/m/Y') }}</td>
        <td class="label">Poids</td>
        <td>{{ $shipment->weight_kg ?? '—' }} kg</td>
    </tr>
    @endif
</table>

@if($instructions)
<div class="instructions-box">
    <strong>Instructions spéciales :</strong> {{ $instructions }}
</div>
@endif

{{-- Contenu du colis --}}
@if($shipment && $shipment->items && $shipment->items->count() > 0)
<div class="section-title">Contenu déclaré</div>
<table>
    <thead>
        <tr style="background-color: #f1f5f9;">
            <th style="border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; font-size: 10px;">Description</th>
            <th style="border: 1px solid #cbd5e1; padding: 4px 6px; text-align: center; font-size: 10px; width: 12%;">Qté</th>
            <th style="border: 1px solid #cbd5e1; padding: 4px 6px; text-align: center; font-size: 10px; width: 18%;">Valeur</th>
        </tr>
    </thead>
    <tbody>
        @foreach($shipment->items as $item)
        <tr>
            <td style="border: 1px solid #cbd5e1; padding: 4px 6px; font-size: 10px;">{{ $item->description }}</td>
            <td style="border: 1px solid #cbd5e1; padding: 4px 6px; text-align: center; font-size: 10px;">{{ $item->quantity ?? 1 }}</td>
            <td style="border: 1px solid #cbd5e1; padding: 4px 6px; text-align: right; font-size: 10px;">
                {{ $item->value ? number_format((float) $item->value, 2).' '.($shipment->currency ?? 'USD') : '—' }}
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Checklist chauffeur --}}
<div class="section-title">Checklist chauffeur</div>
<div style="padding: 8px; border: 1px solid #cbd5e1;">
    <div class="check-row"><span class="check-box"></span> Colis remis en mains propres au destinataire</div>
    <div class="check-row"><span class="check-box"></span> Colis en bon état apparent à la remise</div>
    <div class="check-row"><span class="check-box"></span> Identité du destinataire vérifiée</div>
    <div class="check-row"><span class="check-box"></span> Photo de confirmation prise</div>
</div>

{{-- Signatures --}}
<table class="sig-table" style="margin-top: 40px;">
    <tr>
        <td class="sig-box">
            <span class="sig-label">Signature du chauffeur<br>{{ $driverName }}</span>
        </td>
        <td style="width: 12%;"></td>
        <td class="sig-box">
            <span class="sig-label">Signature du {{ $type === 'Ramassage' ? 'remettant' : 'destinataire' }}<br>(Bon pour reçu)</span>
        </td>
    </tr>
</table>

<div class="footer">
    Monrespro Logistic · Bon généré le {{ $generatedAt }} · Conserver ce document
</div>

</body>
</html>
