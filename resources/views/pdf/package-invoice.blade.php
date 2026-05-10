<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reçu de dépôt — {{ $package->reference_code ?? 'MRP' }}</title>
    <style>
        @page { margin: 18px; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #000; margin: 0; padding: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        td { vertical-align: top; }
        .header-table td { border: none; }
        .logo-cell { width: 30%; }
        .title-cell { width: 40%; text-align: center; }
        .qr-cell { width: 30%; text-align: right; }
        .qr-img { width: 90px; height: 90px; }
        .section-title { font-weight: bold; font-size: 12px; background-color: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 8px; margin-bottom: 4px; }
        .info-table td { border: 1px solid #cbd5e1; padding: 5px 8px; }
        .info-table .label { background-color: #f8fafc; font-weight: bold; width: 40%; }
        .badge { display: inline-block; background-color: #2563eb; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: bold; }
        .total-row td { font-weight: bold; font-size: 13px; border: 2px solid #000; padding: 6px 8px; }
        .signature-section { margin-top: 60px; }
        .sig-box { width: 44%; border-top: 1px solid #000; padding-top: 8px; min-height: 60px; }
        .sig-label { font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 30px; }
        .footer { margin-top: 20px; border-top: 1px solid #cbd5e1; padding-top: 8px; font-size: 9px; color: #666; text-align: center; }
        .ref-large { font-size: 20px; font-weight: 900; letter-spacing: 2px; text-align: center; margin: 8px 0; }
    </style>
</head>
<body>
@php
    $s = $settings ?? collect([]);
    $siteName = $s['site_name'] ?? 'MONRESPRO';
    $ref = $package->reference_code ?? '—';
    $user = $package->user ?? null;
    $locker = $package->locker ?? null;
    $createdAt = isset($package->created_at) ? \Carbon\Carbon::parse($package->created_at) : now();
@endphp

{{-- En-tête --}}
<table class="header-table">
    <tr>
        <td class="logo-cell">
            @if(!empty($s['logo_data_uri']))
                <img src="{{ $s['logo_data_uri'] }}" alt="Logo" height="40">
            @else
                <div style="font-size: 18px; font-weight: 900; color: #2563eb;">{{ $siteName }}</div>
            @endif
        </td>
        <td class="title-cell">
            <div style="font-size: 14px; font-weight: 900; text-transform: uppercase; letter-spacing: 2px;">Reçu de dépôt</div>
            <div style="font-size: 10px; color: #475569;">Confirmation de réception au hub</div>
            @if(!empty($s['address']))
                <div style="font-size: 9px; color: #64748b; margin-top: 4px;">{{ $s['address'] }}</div>
            @endif
        </td>
        <td class="qr-cell">
            @if(!empty($qr_data_uri))
                <img src="{{ $qr_data_uri }}" class="qr-img" alt="QR suivi">
            @endif
            <div style="font-size: 8px; text-align: right; margin-top: 2px;">{{ $ref }}</div>
        </td>
    </tr>
</table>

<div class="ref-large">{{ $ref }}</div>
@if(!empty($barcode_data_uri))
    <div style="text-align: center; margin: 4px 0;">
        <img src="{{ $barcode_data_uri }}" style="height: 36px; max-width: 280px;" alt="Code-barres">
    </div>
@endif

{{-- Informations client --}}
<div class="section-title">Client</div>
<table class="info-table" style="margin-bottom: 10px;">
    <tr>
        <td class="label">Nom</td>
        <td>{{ $user?->name ?? '—' }}</td>
        <td class="label">Casier</td>
        <td>{{ $locker?->code ?? $user?->locker_number ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Téléphone</td>
        <td>{{ $user?->phone ?? '—' }}</td>
        <td class="label">Email</td>
        <td>{{ $user?->email ?? '—' }}</td>
    </tr>
</table>

{{-- Détails du colis --}}
<div class="section-title">Détails du colis</div>
<table class="info-table" style="margin-bottom: 10px;">
    <tr>
        <td class="label">Description</td>
        <td colspan="3">{{ $package->description ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Marchand / Origine</td>
        <td>{{ $package->merchant_name ?? '—' }}</td>
        <td class="label">Date de réception</td>
        <td>{{ $createdAt->format('d/m/Y H:i') }}</td>
    </tr>
    <tr>
        <td class="label">Poids réel (kg)</td>
        <td>{{ $package->weight_kg ?? '—' }}</td>
        <td class="label">Valeur déclarée</td>
        <td>
            @if(!empty($package->declared_value))
                {{ number_format((float) $package->declared_value, 2) }} {{ $package->value_currency ?? 'USD' }}
            @else
                —
            @endif
        </td>
    </tr>
    @if(!empty($package->condition_notes))
    <tr>
        <td class="label">Observations</td>
        <td colspan="3">{{ $package->condition_notes }}</td>
    </tr>
    @endif
    <tr>
        <td class="label">N° suivi vendeur</td>
        <td>{{ $package->vendor_tracking_number ?? '—' }}</td>
        <td class="label">Transporteur</td>
        <td>{{ $package->carrier_name ?? '—' }}</td>
    </tr>
</table>

{{-- Statut --}}
<div style="margin: 10px 0;">
    <span class="badge">REÇU AU HUB</span>
    <span style="font-size: 10px; margin-left: 8px; color: #475569;">Le {{ $createdAt->format('d/m/Y à H:i') }}</span>
</div>

{{-- Mentions --}}
<div style="margin-top: 12px; font-size: 9px; color: #475569; border: 1px solid #e2e8f0; padding: 8px; background-color: #f8fafc;">
    Ce reçu confirme la prise en charge physique du colis ci-dessus par {{ $siteName }}. La valeur indiquée est celle déclarée par le client. {{ $siteName }} ne peut être tenu responsable au-delà du montant déclaré en cas de sinistre.
</div>

{{-- Signatures --}}
<div class="signature-section">
    <table>
        <tr>
            <td class="sig-box" style="border: none; border-top: 1px solid #000;">
                <span class="sig-label">Signature de l'opérateur</span>
                @if(!empty($operator_name))
                    <div style="font-size: 9px; color: #475569;">{{ $operator_name }}</div>
                @endif
            </td>
            <td style="border: none; width: 12%;"></td>
            <td class="sig-box" style="border: none; border-top: 1px solid #000;">
                <span class="sig-label">Signature du client (bon pour reçu)</span>
            </td>
        </tr>
    </table>
</div>

<div class="footer">
    {{ $siteName }} · Reçu généré le {{ now()->format('d/m/Y à H:i') }} · Document non contractuel sans signature
</div>

</body>
</html>
