<x-mail::message>
# Votre devis est prêt

Bonjour **{{ $clientFirstName }}**,

Nous avons le plaisir de vous présenter le **devis n° {{ $purchase->id }}** pour votre demande d’**achat assisté**. **Le même devis est joint à cet e-mail au format PDF** pour vos archives. Chaque ligne a été vérifiée avec le même soin qu’une conciergerie privée.

<x-mail::panel>
Récapitulatif de votre sélection — montants en **{{ $currency }}**.
</x-mail::panel>

@if(count($quoteRows) > 0)
<x-mail::table>
| Article | Qté | Prix unitaire | Total ligne |
|:--------|:---:|:-------------:|------------:|
@foreach($quoteRows as $row)
| {{ $row['name'] }} | {{ $row['quantity'] }} | {{ $row['unit_formatted'] }} | {{ $row['line_formatted'] }} |
@endforeach
</x-mail::table>
@endif

<x-mail::panel>
**Sous-total articles** — {{ $linesSubtotalFormatted }}  
**Frais de service** — {{ $serviceFeeFormatted }}  
**Frais bancaires** ({{ $bankFeePercentageLabel }}) — {{ $bankFeeFormatted }}

**TOTAL À PAYER — {{ $totalFormatted }}**
</x-mail::panel>

@if($paymentMethodsNote)
<p style="margin-top: 20px; font-size: 13px; color: #64748b; line-height: 1.55;">
{{ $paymentMethodsNote }}
</p>
@endif

<x-mail::button :url="$paymentUrl" color="success">
Payer mon devis
</x-mail::button>

Une question ? Répondez à cet e-mail ou connectez-vous à votre espace pour suivre l’avancement.

Cordialement,<br>
{{ config('app.name', 'Monrespro') }}

<x-slot:subcopy>
Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur : [{{ $paymentUrl }}]({{ $paymentUrl }})
</x-slot:subcopy>
</x-mail::message>
