<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport de suivi — {{ $shipment->public_tracking }}</title>
    <style>
        @page { margin: 20px; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #000; margin: 0; padding: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        td { vertical-align: top; }
        .header-table td { border: none; }
        .info-table td { border: 1px solid #cbd5e1; padding: 5px 8px; }
        .info-table .label { background-color: #f8fafc; font-weight: bold; width: 30%; }
        .section-title { font-weight: bold; font-size: 12px; background-color: #1e3a5f; color: #fff; padding: 5px 10px; margin: 12px 0 4px 0; letter-spacing: 1px; text-transform: uppercase; }
        .timeline-item { border-left: 3px solid #2563eb; padding: 6px 8px 6px 12px; margin-bottom: 6px; background-color: #f8fafc; }
        .timeline-date { font-size: 9px; color: #64748b; font-weight: bold; }
        .timeline-title { font-weight: bold; font-size: 11px; margin: 2px 0; }
        .timeline-desc { font-size: 10px; color: #475569; }
        .timeline-user { font-size: 9px; color: #94a3b8; font-style: italic; }
        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 3px; font-size: 10px; font-weight: bold; background-color: #2563eb; color: #fff; }
        .qr-cell { width: 25%; text-align: right; }
        .qr-img { width: 80px; height: 80px; }
    </style>
</head>
<body>
@php
    $sp = $shipment->senderProfile;
    $rp = $shipment->recipientProfile;
    $tracking = $shipment->public_tracking ?? '—';
    $logs = $logs ?? collect([]);
@endphp

{{-- En-tête --}}
<table class="header-table">
    <tr>
        <td style="width: 75%;">
            <div style="font-size: 22px; font-weight: 900; color: #2563eb; margin-bottom: 2px;">MONRESPRO</div>
            <div style="font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px;">Rapport de suivi d'expédition</div>
            <div style="font-size: 11px; font-weight: 900; color: #1e3a5f; margin-top: 4px;">N° {{ $tracking }}</div>
            <div style="font-size: 9px; color: #64748b; margin-top: 2px;">Généré le {{ now()->format('d/m/Y à H:i') }}</div>
        </td>
        <td class="qr-cell">
            @if(!empty($shipment->public_tracking))
                @php
                    $qr = \App\Support\QrCodeHelper::trackingDataUri($tracking, 80);
                    $bc = \App\Support\QrCodeHelper::barcodeDataUri($tracking);
                @endphp
                <img src="{{ $qr }}" class="qr-img" alt="QR suivi">
                <div style="font-size: 8px; text-align: right; margin-top: 2px;">{{ $tracking }}</div>
            @endif
        </td>
    </tr>
</table>

{{-- Informations expédition --}}
<div class="section-title">Informations expédition</div>
<table class="info-table">
    <tr>
        <td class="label">Expéditeur</td>
        <td>{{ $sp?->full_name ?? '—' }} · {{ $sp?->phone ?? '' }}</td>
        <td class="label">Statut actuel</td>
        <td><span class="status-badge">{{ $shipment->status?->label() ?? $shipment->status ?? '—' }}</span></td>
    </tr>
    <tr>
        <td class="label">Destinataire</td>
        <td>{{ $rp?->full_name ?? '—' }} · {{ $rp?->phone ?? '' }}</td>
        <td class="label">Date de création</td>
        <td>{{ $shipment->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Origine</td>
        <td>{{ $shipment->originCountry?->name ?? $sp?->country?->name ?? '—' }}</td>
        <td class="label">Destination</td>
        <td>{{ $shipment->destCountry?->name ?? $rp?->country?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Poids</td>
        <td>{{ $shipment->weight_kg ?? '—' }} kg</td>
        <td class="label">Valeur déclarée</td>
        <td>
            @if(!empty($shipment->declared_value))
                {{ number_format((float) $shipment->declared_value, 2) }} {{ $shipment->currency ?? 'USD' }}
            @else—@endif
        </td>
    </tr>
</table>

{{-- Historique complet --}}
<div class="section-title">Historique chronologique</div>

@if($logs->isEmpty())
    <p style="color: #94a3b8; font-style: italic; padding: 8px;">Aucun événement enregistré pour cette expédition.</p>
@else
    @foreach($logs as $log)
    <div class="timeline-item">
        <div class="timeline-date">
            {{ \Carbon\Carbon::parse($log->created_at)->format('d/m/Y à H:i') }}
            @if(!empty($log->ip_address))
                · IP {{ $log->ip_address }}
            @endif
        </div>
        <div class="timeline-title">{{ $log->title ?? $log->status ?? '—' }}</div>
        @if(!empty($log->description))
            <div class="timeline-desc">{{ $log->description }}</div>
        @endif
        @if(!empty($log->user?->name))
            <div class="timeline-user">Par : {{ $log->user->name }}</div>
        @endif
    </div>
    @endforeach
@endif

{{-- Pied de page --}}
<div style="margin-top: 20px; border-top: 1px solid #cbd5e1; padding-top: 8px; font-size: 9px; color: #94a3b8; text-align: center;">
    Ce document est généré automatiquement par Monrespro Logistic. Il constitue un relevé d'activité officiel et immuable.
    En cas de litige, ce document peut être utilisé comme preuve de traitement.
</div>

</body>
</html>
