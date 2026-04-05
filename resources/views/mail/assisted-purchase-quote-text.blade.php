{{ config('app.name', 'Monrespro') }} — Devis achat assisté #{{ $purchase->id }}

Bonjour {{ $clientFirstName }},

Votre devis est disponible (montants en {{ $currency }}). Une copie PDF est jointe à cet e-mail.

@foreach($quoteRows as $row)
- {{ $row['name'] }} × {{ $row['quantity'] }} — {{ $row['unit_formatted'] }} / unité — ligne : {{ $row['line_formatted'] }}
@endforeach

Sous-total articles : {{ $linesSubtotalFormatted }}
Frais de service : {{ $serviceFeeFormatted }}
Frais bancaires ({{ $bankFeePercentageLabel }}) : {{ $bankFeeFormatted }}
TOTAL À PAYER : {{ $totalFormatted }}

@if($paymentMethodsNote)
{{ $paymentMethodsNote }}

@endif
Payer / suivre : {{ $paymentUrl }}

— {{ config('app.name', 'Monrespro') }}
