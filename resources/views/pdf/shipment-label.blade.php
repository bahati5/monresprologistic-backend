<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Étiquette — {{ $shipment->public_tracking }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 10px; color: #000; margin: 0; padding: 15px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        td { vertical-align: top; border: 1px solid #000; padding: 4px; }
        
        .header { text-align: center; margin-bottom: 10px; border-bottom: 2px solid #000; padding-bottom: 8px; }
        .logo-img { height: 35px; filter: grayscale(100%); margin-bottom: 4px; }
        
        .qr-section { text-align: center; margin: 10px 0; }
        .qr-img { width: 140px; height: 140px; }
        
        .tracking-num { font-size: 16px; font-weight: 900; text-align: center; margin: 5px 0; letter-spacing: 1px; }
        
        .locker-box { background-color: #000; color: #fff; text-align: center; font-size: 18px; font-weight: 900; padding: 6px 0; margin: 8px 0; text-transform: uppercase; }
        
        .dest-title { font-size: 22px; font-weight: 900; text-align: center; border-top: 2px solid #000; border-bottom: 2px solid #000; padding: 8px 0; margin: 8px 0; text-transform: uppercase; }
        
        .actor-label { font-size: 8px; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 2px; }
        .actor-name { font-size: 11px; font-weight: 900; }
        
        .meta-row { text-align: center; font-size: 9px; margin-top: 5px; }

        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
@php
    $sp = $shipment->senderProfile;
    $rp = $shipment->recipientProfile;
    $recipName = $rp?->full_name ?? '—';
    $recipPhone = $rp?->phone ?? '';
    $recipCity = $rp?->city?->name ?? '';
    $recipCountry = $rp?->country?->name ?? '';
    if (is_array($recipCountry)) {
        $recipCountry = $recipCountry['fr'] ?? $recipCountry['en'] ?? reset($recipCountry) ?? '';
    }
    $recipCountry = is_string($recipCountry) ? $recipCountry : '';

    $senderName = $sp?->company_name ?? $sp?->full_name ?? '—';
    $senderLocker = $shipment->creator?->locker?->code ?? null;

    $originCountry = $shipment->originCountry?->name ?? $sp?->country?->name ?? '';
    $destCountry = $shipment->destCountry?->name ?? $rp?->country?->name ?? '';
    if (is_array($originCountry)) {
        $originCountry = $originCountry['fr'] ?? $originCountry['en'] ?? reset($originCountry) ?? '';
    }
    if (is_array($destCountry)) {
        $destCountry = $destCountry['fr'] ?? $destCountry['en'] ?? reset($destCountry) ?? '';
    }
    $senderCity = $sp?->city?->name ?? '';
    if (is_array($senderCity)) {
        $senderCity = $senderCity['fr'] ?? $senderCity['en'] ?? reset($senderCity) ?? '';
    }
    if (is_array($recipCity)) {
        $recipCity = $recipCity['fr'] ?? $recipCity['en'] ?? reset($recipCity) ?? '';
    }
    $routeMain = trim(implode(' → ', array_filter([is_string($originCountry) ? trim($originCountry) : '', is_string($destCountry) ? trim($destCountry) : ''])));
    if ($routeMain === '') {
        $routeMain = trim(implode(' → ', array_filter([$recipCity, $recipCountry])));
    }
    if ($routeMain === '') {
        $routeMain = $recipName;
    }
    $destLine = $routeMain;
@endphp

<div class="label-page">
    {{-- HEADER --}}
    <div class="header">
        @if(!empty($doc['logo_data_uri']))
            <img src="{{ $doc['logo_data_uri'] }}" class="logo-img" alt="Logo"><br>
        @else
            <div style="font-size: 18px; font-weight: 900;">{{ $doc['site_name'] ?? 'MONRESPRO' }}</div>
        @endif
        <div style="font-size: 8px; color: #333;">
            {{ $doc['phone'] ?? '' }} · {{ $doc['site_email'] ?? '' }}
        </div>
    </div>

    {{-- QR CODE --}}
    @if(!empty($tracking_qr_data_uri))
    <div class="qr-section">
        <img src="{{ $tracking_qr_data_uri }}" class="qr-img" alt="QR Suivi">
    </div>
    @endif

    {{-- BARCODE --}}
    @if(!empty($tracking_barcode_data_uri))
    <div style="text-align: center; margin: 6px 0;">
        <img src="{{ $tracking_barcode_data_uri }}" style="width: 180px; height: 40px;" alt="Code-barres">
    </div>
    @endif

    {{-- TRACKING --}}
    <div class="tracking-num">{{ $shipment->public_tracking ?? '—' }}</div>

    {{-- CASIER --}}
    @if($senderLocker)
    <div class="locker-box">CASIER : {{ $senderLocker }}</div>
    @endif

    {{-- DESTINATION --}}
    <div class="dest-title">{{ $destLine }}</div>

    {{-- ACTORS --}}
    <table>
        <tr>
            <td style="width: 50%;">
                <div class="actor-label">Expéditeur</div>
                <div class="actor-name">{{ \Illuminate\Support\Str::limit($senderName, 25) }}</div>
                <div style="font-size: 8px;">{{ $sp?->phone ?? '' }}</div>
            </td>
            <td style="width: 50%;">
                <div class="actor-label">Destinataire</div>
                <div class="actor-name">{{ \Illuminate\Support\Str::limit($recipName, 25) }}</div>
                <div style="font-size: 8px;">{{ $recipPhone }}</div>
            </td>
        </tr>
    </table>

    {{-- META --}}
    <div class="meta-row">
        <strong>{{ $shipment->created_at?->format('d/m/Y') }}</strong> ·
        Poids: <strong>{{ $metrics['billing_weight'] }} {{ $doc['weight_unit'] ?? 'kg' }}</strong> ·
        Items: <strong>{{ $metrics['item_count'] }}</strong>
    </div>
    <div class="meta-row" style="font-size: 10px; font-weight: bold; margin-top: 2px;">
        {{ $logistics['shippingMode'] }} · {{ $logistics['deliveryTime'] }}
    </div>
</div>

</body>
</html>
