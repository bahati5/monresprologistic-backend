@php
    /** @var \App\Models\AssistedPurchase $purchase */
    /** @var array $present */
    /** @var list<array{label: string, value: string}> $clientRows */
    $doc = $present['doc'] ?? [];
    $site = $doc['site_name'] ?? config('app.name', 'Monrespro');
    $pdfSym = trim((string) ($doc['currency_symbol'] ?? '€'));
    $pdfSuffix = ((string) (\App\Models\Setting::getValue('symbol_position', 'prefix') ?: 'prefix')) === 'suffix';
    $pdfDec = max(0, min(6, (int) ($doc['decimals'] ?? 2)));
    $pdfFmt = static function (float $n) use ($pdfSym, $pdfSuffix, $pdfDec): string {
        $num = number_format($n, $pdfDec, ',', ' ');
        $sp = html_entity_decode('&nbsp;', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $pdfSuffix ? $num.$sp.$pdfSym : $pdfSym.$num;
    };
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Devis #{{ $purchase->id }} — {{ $site }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5pt;
            color: #1a1a22;
            margin: 0;
            padding: 28px 32px 36px;
            line-height: 1.45;
        }
        .accent { color: {{ $accent }}; }
        .header-row {
            width: 100%;
            margin-bottom: 28px;
        }
        .header-row td { vertical-align: top; }
        .brand-name {
            font-size: 16pt;
            font-weight: bold;
            color: {{ $accent }};
            margin: 0 0 6px 0;
        }
        .brand-meta {
            font-size: 9pt;
            color: #5c5c6e;
            line-height: 1.5;
        }
        .devis-title {
            text-align: right;
            font-size: 22pt;
            font-weight: bold;
            letter-spacing: 0.06em;
            color: {{ $accent }};
            margin: 0;
        }
        .devis-meta {
            text-align: right;
            font-size: 9.5pt;
            color: #5c5c6e;
            margin-top: 6px;
        }
        .section-label {
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #7a7a8c;
            margin: 0 0 8px 0;
        }
        .client-box {
            border: 1px solid #e2e2ea;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 22px;
            background: #fafafc;
        }
        .client-row {
            width: 100%;
            margin-bottom: 8px;
        }
        .client-row:last-child { margin-bottom: 0; }
        .client-row td { vertical-align: top; padding: 3px 0; }
        .c-lab {
            font-size: 8pt;
            color: #7a7a8c;
            width: 32%;
        }
        .c-val {
            font-size: 10pt;
            font-weight: bold;
            white-space: pre-line;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.items thead th {
            background: {{ $accent }};
            color: #fff;
            font-size: 9pt;
            font-weight: bold;
            padding: 10px 8px;
            text-align: left;
        }
        table.items thead th.num { text-align: right; }
        table.items tbody td {
            border-bottom: 1px solid #e8e8ef;
            padding: 10px 8px;
            vertical-align: top;
        }
        table.items tbody td.num {
            text-align: right;
            font-weight: bold;
        }
        .item-name { font-weight: bold; font-size: 10.5pt; }
        .item-sub { font-size: 8.5pt; color: #6b6b7a; margin-top: 4px; }
        .totals-wrap {
            width: 100%;
            margin-top: 18px;
        }
        .totals-wrap td { vertical-align: top; }
        .note-block {
            font-size: 9pt;
            color: #4a4a58;
            padding-right: 24px;
        }
        .note-block strong { color: #333; }
        .totals {
            width: 260px;
            margin-left: auto;
        }
        .totals tr td {
            padding: 5px 0;
            font-size: 9.5pt;
        }
        .totals tr td:first-child { text-align: right; color: #5c5c6e; padding-right: 12px; }
        .totals tr td:last-child { text-align: right; font-weight: bold; }
        .total-due {
            background: {{ $accent }};
            color: #fff !important;
            padding: 10px 12px !important;
            margin-top: 6px;
            border-radius: 6px;
        }
        .total-due td { color: #fff !important; font-size: 11pt !important; }
        .footer {
            margin-top: 32px;
            padding-top: 16px;
            border-top: 1px solid #e2e2ea;
            font-size: 8.5pt;
            color: #6b6b7a;
            text-align: center;
        }
    </style>
</head>
<body>

<table class="header-row">
    <tr>
        <td style="width:55%">
            @if(!empty($doc['logo_data_uri']))
                <img src="{{ $doc['logo_data_uri'] }}" alt="" style="max-height:44px;max-width:160px;margin-bottom:8px;">
            @endif
            <p class="brand-name">{{ $site }}</p>
            <div class="brand-meta">
                @if(!empty($doc['address'])){{ $doc['address'] }}<br>@endif
                @php
                    $cityLine = trim(($doc['zip_code'] ?? '').' '.($doc['city'] ?? ''));
                @endphp
                @if($cityLine !== ''){{ $cityLine }}<br>@endif
                @if(!empty($doc['country'])){{ $doc['country'] }}<br>@endif
                @if(!empty($doc['phone']))Tél. {{ $doc['phone'] }}<br>@endif
                @if(!empty($doc['site_email'])){{ $doc['site_email'] }}@endif
            </div>
        </td>
        <td style="width:45%">
            <p class="devis-title">DEVIS</p>
            <p class="devis-meta">N° {{ $purchase->id }}<br>{{ $quotedAtFormatted }}</p>
        </td>
    </tr>
</table>

<p class="section-label">Client</p>
<div class="client-box">
    @forelse($clientRows as $row)
        <table class="client-row">
            <tr>
                <td class="c-lab">{{ $row['label'] }}</td>
                <td class="c-val">{{ $row['value'] }}</td>
            </tr>
        </table>
    @empty
        <p style="margin:0;color:#7a7a8c;">—</p>
    @endforelse
</div>

<p class="section-label">Détail des articles</p>
<table class="items">
    <thead>
        <tr>
            <th>Description</th>
            <th class="num" style="width:14%">Prix unit.</th>
            <th class="num" style="width:10%">Qté</th>
            <th class="num" style="width:16%">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($purchase->items as $item)
            @php
                $qty = (int) $item->quantity;
                $unit = (float) $item->unit_price;
                $line = $unit * $qty;
            @endphp
            <tr>
                <td>
                    <div class="item-name">{{ $item->display_label }}</div>
                    @if(!empty($item->options))
                        <div class="item-sub">{{ $item->options }}</div>
                    @endif
                    @if(!empty($item->url))
                        <div class="item-sub">{{ \Illuminate\Support\Str::limit((string) $item->url, 96) }}</div>
                    @endif
                </td>
                <td class="num">{{ $pdfFmt($unit) }}</td>
                <td class="num">{{ $qty }}</td>
                <td class="num">{{ $pdfFmt($line) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals-wrap">
    <tr>
        <td style="width:52%">
            @if(!empty($present['paymentMethodsNote']))
                <div class="note-block">
                    <strong>Moyens de paiement</strong><br>
                    {!! nl2br(e($present['paymentMethodsNote'])) !!}
                </div>
            @else
                <div class="note-block">
                    <strong>Merci pour votre confiance.</strong><br>
                    Réglez votre devis depuis votre espace client après réception de ce document.
                </div>
            @endif
            @if(!empty($present['paymentUrl']))
                <div class="note-block" style="margin-top: 12px;">
                    <strong>Accéder au devis en ligne (connexion requise)</strong><br>
                    <span style="font-size: 9pt; word-break: break-all; color: #3d3d69;">{{ $present['paymentUrl'] }}</span>
                </div>
            @endif
        </td>
        <td style="width:48%">
            <table class="totals">
                <tr>
                    <td>Sous-total articles</td>
                    <td>{{ $present['linesSubtotalFormatted'] }}</td>
                </tr>
                <tr>
                    <td>Frais de service</td>
                    <td>{{ $present['serviceFeeFormatted'] }}</td>
                </tr>
                <tr>
                    <td>Frais bancaires ({{ $present['bankFeePercentageLabel'] }})</td>
                    <td>{{ $present['bankFeeFormatted'] }}</td>
                </tr>
                <tr class="total-due">
                    <td>TOTAL À PAYER</td>
                    <td>{{ $present['totalFormatted'] }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="footer">
    {{ $site }} — Document généré automatiquement. Pour toute question, contactez-nous aux coordonnées ci-dessus.
</div>

</body>
</html>
