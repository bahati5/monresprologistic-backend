<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Étiquette — {{ $shipment->public_tracking }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 10px; color: #1e293b; margin: 0; padding: 0; }
        .label-page { width: 283px; height: 425px; padding: 10px; position: relative; overflow: hidden; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 6px; }
        .brand-name { font-size: 16px; font-weight: 800; letter-spacing: -0.3px; }
        .brand-sub { font-size: 7px; color: #666; margin-top: 1px; }
        .qr-main { text-align: center; margin: 6px 0; }
        .qr-main img { width: 180px; height: 180px; display: inline-block; }
        .tracking { font-family: 'Courier New', Courier, monospace; font-size: 18px; font-weight: 900; text-align: center; letter-spacing: 2px; margin: 4px 0; }
        .locker-id { text-align: center; margin: 5px 0; padding: 4px 0; background: #000; color: #fff; font-size: 14px; font-weight: 800; letter-spacing: 1px; }
        .destination { text-align: center; font-size: 20px; font-weight: 900; margin: 6px 0; padding: 5px 0; border-top: 1px solid #333; border-bottom: 1px solid #333; text-transform: uppercase; }
        .actors { width: 100%; margin: 6px 0; }
        .actors td { width: 50%; vertical-align: top; padding: 0; font-size: 8px; }
        .actors td:first-child { padding-right: 4px; }
        .actors td:last-child { padding-left: 4px; }
        .actor-box { border: 1px solid #ccc; border-radius: 3px; padding: 4px 5px; height: 60px; }
        .actor-title { font-size: 7px; font-weight: 700; text-transform: uppercase; color: #888; margin-bottom: 2px; }
        .actor-name { font-size: 10px; font-weight: 700; }
        .actor-detail { font-size: 7px; color: #555; }
        .meta-row { text-align: center; font-size: 7px; color: #555; margin: 4px 0; }
        .meta-row strong { color: #000; }
        .payment-strip { text-align: center; margin: 4px 0; padding: 3px 0; font-size: 10px; font-weight: 800; border-radius: 3px; }
        .pay-ok { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .pay-no { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        .no-print { display: none; }
        @if(!empty($preview)) .no-print { display: block; } @endif
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
@php
    $sender = $shipment->sender;
    $senderClient = $shipment->senderClient;
    $recipient = $shipment->recipient;
    $deliveryRecipient = $shipment->deliveryRecipient ?? null;

    $senderName = $senderClient->company_name ?? $senderClient->name ?? $sender?->name ?? '—';
    $senderLocker = $sender?->locker?->code ?? null;

    $recipName = $deliveryRecipient->name ?? $recipient?->name ?? '—';
    $recipPhone = $deliveryRecipient->phone ?? $recipient?->phone ?? '';
    $recipCity = $deliveryRecipient->city ?? '';
    $recipCountry = $deliveryRecipient->country ?? '';

    $destLine = trim(implode(' → ', array_filter([$recipCity, $recipCountry])));
    if ($destLine === '') $destLine = $recipName;

    $cur = $shipment->currency ?? $doc['currency'] ?? 'USD';
    $payStatus = $shipment->payment_status ?? 'unpaid';
    $isPaid = $invoice_paid || $payStatus === 'paid';

    $serviceLabel = is_array($shipment->serviceType?->name)
        ? ($shipment->serviceType->name['fr'] ?? reset($shipment->serviceType->name))
        : ($shipment->serviceType?->name ?? '');
    $log = $logistics ?? ['shippingMode' => '—', 'deliveryTime' => '—', 'transport' => '—', 'shipLine' => '—'];
@endphp

<div class="label-page">
    {{-- HEADER --}}
    <div class="header">
        @if(!empty($doc['logo_data_uri']))
            <img src="{{ $doc['logo_data_uri'] }}" alt="{{ $doc['site_name'] }}" height="22" style="filter:grayscale(100%);"><br>
        @elseif(!empty($doc['logo_url']))
            <img src="{{ $doc['logo_url'] }}" alt="{{ $doc['site_name'] }}" height="22" style="filter:grayscale(100%);"><br>
        @else
            <div class="brand-name">{{ $doc['site_name'] ?? 'MONRESPRO' }}</div>
        @endif
        <div class="brand-sub">{{ $doc['phone'] ?? '' }} · {{ $doc['site_email'] ?? '' }}</div>
    </div>

    {{-- QR suivi --}}
    @if(!empty($tracking_qr_data_uri))
    <div class="qr-main">
        <img src="{{ $tracking_qr_data_uri }}" alt="QR suivi">
    </div>
    @endif

    {{-- TRACKING NUMBER --}}
    <div class="tracking">{{ $shipment->public_tracking ?? '—' }}</div>

    {{-- LOCKER ID --}}
    @if($senderLocker)
    <div class="locker-id">CASIER : {{ $senderLocker }}</div>
    @endif

    {{-- DESTINATION --}}
    <div class="destination">{{ $destLine }}</div>

    {{-- SENDER / RECIPIENT --}}
    <table class="actors">
        <tr>
            <td>
                <div class="actor-box">
                    <div class="actor-title">Expéditeur</div>
                    <div class="actor-name">{{ \Illuminate\Support\Str::limit($senderName, 28) }}</div>
                    <div class="actor-detail">{{ $sender?->email ?? $senderClient->email ?? '' }}</div>
                    <div class="actor-detail">{{ $sender?->phone ?? $senderClient->phone ?? '' }}</div>
                </div>
            </td>
            <td>
                <div class="actor-box">
                    <div class="actor-title">Destinataire</div>
                    <div class="actor-name">{{ \Illuminate\Support\Str::limit($recipName, 28) }}</div>
                    <div class="actor-detail">{{ $recipPhone }}</div>
                    <div class="actor-detail">{{ $recipCity }}@if($recipCity && $recipCountry), @endif{{ $recipCountry }}</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- SHIPMENT META --}}
    <div class="meta-row">
        <strong>{{ $shipment->created_at?->format('d/m/Y') }}</strong> ·
        Colis : <strong>{{ $metrics['item_count'] }}</strong> ·
        Poids : <strong>{{ $metrics['billing_weight'] }} {{ $doc['weight_unit'] ?? 'kg' }}</strong> ·
        <strong>{{ number_format((float) ($shipment->calculated_price ?? 0), 2) }} {{ $cur }}</strong>
    </div>
    <div class="meta-row">
        @if(($log['shippingMode'] ?? '—') !== '—')<strong>{{ $log['shippingMode'] }}</strong>@else{{ $serviceLabel }}@endif
        @if(($log['deliveryTime'] ?? '—') !== '—') · {{ $log['deliveryTime'] }} @endif
    </div>
    <div class="meta-row">
        @if(($log['transport'] ?? '—') !== '—'){{ $log['transport'] }}@endif
        @if(($log['transport'] ?? '—') !== '—' && ($log['shipLine'] ?? '—') !== '—') · @endif
        @if(($log['shipLine'] ?? '—') !== '—'){{ $log['shipLine'] }}@endif
    </div>

    {{-- PAYMENT STATUS --}}
    <div class="payment-strip {{ $isPaid ? 'pay-ok' : 'pay-no' }}">
        {{ $isPaid ? '✓ PAYÉ' : '✗ NON PAYÉ' }}
    </div>

</div>

@if(!empty($preview))
<div class="no-print" style="text-align:center; margin-top:15px;">
    <button onclick="window.print()" style="padding:8px 20px; background:#333; color:#fff; border:none; border-radius:4px; font-size:13px; cursor:pointer;">
        Imprimer
    </button>
</div>
@endif
</body>
</html>
