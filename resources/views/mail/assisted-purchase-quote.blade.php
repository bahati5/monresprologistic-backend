<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Devis #{{ $purchase->id }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f6f8;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

                    {{-- Header with logo and brand --}}
                    <tr>
                        <td style="background-color:{{ $accentColor }};padding:28px 32px;text-align:center;">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $siteName }}" style="max-height:48px;max-width:200px;margin-bottom:8px;">
                            @else
                                <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;letter-spacing:-0.5px;">{{ $siteName }}</h1>
                            @endif
                        </td>
                    </tr>

                    {{-- Main content --}}
                    <tr>
                        <td style="padding:32px;">

                            {{-- Title --}}
                            <h2 style="margin:0 0 20px;color:{{ $accentColor }};font-size:22px;font-weight:700;">
                                Votre devis est prêt
                            </h2>

                            @if(!empty($templateIntroHtml))
                            <div style="margin:0 0 24px;color:#374151;font-size:15px;line-height:1.6;">
                                {!! $templateIntroHtml !!}
                            </div>
                            @else
                            <p style="margin:0 0 16px;color:#374151;font-size:15px;line-height:1.6;">
                                Bonjour <strong>{{ $clientFirstName }}</strong>,
                            </p>
                            <p style="margin:0 0 24px;color:#374151;font-size:15px;line-height:1.6;">
                                Nous avons le plaisir de vous présenter le <strong>devis n° {{ $purchase->id }}</strong> pour votre demande d'achat assisté. Le même devis est joint à cet e-mail au format PDF pour vos archives.
                            </p>
                            @endif

                            {{-- Currency notice --}}
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:24px;">
                                <tr>
                                    <td style="background-color:#f0f4f8;border-left:4px solid {{ $accentColor }};padding:12px 16px;border-radius:0 6px 6px 0;">
                                        <p style="margin:0;color:#374151;font-size:13px;">
                                            Récapitulatif de votre sélection — montants en <strong>{{ $currency }}</strong>.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            {{-- Articles table --}}
                            @if(count($quoteRows) > 0)
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:24px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                                <thead>
                                    <tr style="background-color:{{ $accentColor }};">
                                        <th style="padding:10px 12px;text-align:left;color:#ffffff;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Article</th>
                                        <th style="padding:10px 12px;text-align:center;color:#ffffff;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:50px;">Qté</th>
                                        <th style="padding:10px 12px;text-align:right;color:#ffffff;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:100px;">Prix unit.</th>
                                        <th style="padding:10px 12px;text-align:right;color:#ffffff;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:100px;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($quoteRows as $i => $row)
                                    <tr style="background-color:{{ $i % 2 === 0 ? '#ffffff' : '#f9fafb' }};">
                                        <td style="padding:10px 12px;color:#374151;font-size:13px;border-top:1px solid #e5e7eb;">{{ $row['name'] }}</td>
                                        <td style="padding:10px 12px;color:#374151;font-size:13px;text-align:center;border-top:1px solid #e5e7eb;">{{ $row['quantity'] }}</td>
                                        <td style="padding:10px 12px;color:#374151;font-size:13px;text-align:right;border-top:1px solid #e5e7eb;">{{ $row['unit_formatted'] }}</td>
                                        <td style="padding:10px 12px;color:#374151;font-size:13px;text-align:right;font-weight:600;border-top:1px solid #e5e7eb;">{{ $row['line_formatted'] }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @endif

                            {{-- Totals section --}}
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:24px;background-color:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                                @if($snapshotData && !empty($snapshotData['lines']))
                                <tr>
                                    <td style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="color:#6b7280;font-size:13px;">Sous-total articles</td>
                                                <td style="text-align:right;color:#374151;font-size:13px;font-weight:600;">{{ $linesSubtotalFormatted }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @foreach($snapshotData['lines'] as $snapLine)
                                @if($snapLine['is_visible_to_client'] ?? true)
                                <tr>
                                    <td style="padding:8px 16px;border-bottom:1px solid #e5e7eb;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                @php
                                                    $snapType = $snapLine['type'] ?? null;
                                                    $snapAmount = (float) ($snapLine['amount'] ?? $snapLine['total'] ?? 0);
                                                    $snapValue = $snapLine['value'] ?? $snapLine['unit_price'] ?? null;
                                                @endphp
                                                <td style="color:#6b7280;font-size:13px;">{{ $snapLine['name'] ?? '—' }}@if($snapType === 'percentage' && $snapValue !== null) ({{ $snapValue }} %)@endif</td>
                                                <td style="text-align:right;color:#374151;font-size:13px;">{{ number_format($snapAmount, 2, ',', ' ') }}&nbsp;{{ $currencySymbol }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                @endforeach
                                @else
                                <tr>
                                    <td style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="color:#6b7280;font-size:13px;">Sous-total articles</td>
                                                <td style="text-align:right;color:#374151;font-size:13px;font-weight:600;">{{ $linesSubtotalFormatted }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 16px;border-bottom:1px solid #e5e7eb;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="color:#6b7280;font-size:13px;">Frais de service</td>
                                                <td style="text-align:right;color:#374151;font-size:13px;">{{ $serviceFeeFormatted }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 16px;border-bottom:1px solid #e5e7eb;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="color:#6b7280;font-size:13px;">Frais bancaires ({{ $bankFeePercentageLabel }})</td>
                                                <td style="text-align:right;color:#374151;font-size:13px;">{{ $bankFeeFormatted }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                {{-- Total row --}}
                                <tr>
                                    <td style="padding:14px 16px;background-color:{{ $accentColor }};">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="color:#ffffff;font-size:15px;font-weight:700;">TOTAL À PAYER</td>
                                                <td style="text-align:right;color:#ffffff;font-size:17px;font-weight:700;">{{ $totalFormatted }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- Info lines --}}
                            @if($estimatedDelivery)
                            <p style="margin:0 0 8px;color:#374151;font-size:14px;line-height:1.5;">
                                <strong>Délai estimé :</strong> {{ $estimatedDelivery }}
                            </p>
                            @endif

                            @if($staffMessage)
                            <p style="margin:0 0 8px;color:#374151;font-size:14px;line-height:1.5;">
                                <strong>Message de votre conseiller :</strong> {{ $staffMessage }}
                            </p>
                            @endif

                            @if($expiresAt)
                            <p style="margin:0 0 16px;color:#dc2626;font-size:13px;line-height:1.5;">
                                Ce devis est valide jusqu'au <strong>{{ $expiresAt }}</strong>. Passé ce délai, il sera automatiquement annulé.
                            </p>
                            @endif

                            {{-- Action buttons --}}
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:28px 0 16px;">
                                <tr>
                                    <td align="center">
                                        @if($responseUrl)
                                        <a href="{{ $responseUrl }}&action=accept" style="display:inline-block;background-color:#16a34a;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:14px 32px;border-radius:8px;letter-spacing:0.3px;">
                                            Accepter le devis
                                        </a>
                                        @else
                                        <a href="{{ $paymentUrl }}" style="display:inline-block;background-color:{{ $accentColor }};color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:14px 32px;border-radius:8px;letter-spacing:0.3px;">
                                            Consulter mon devis
                                        </a>
                                        @endif
                                    </td>
                                </tr>
                                @if($responseUrl)
                                <tr>
                                    <td align="center" style="padding-top:12px;">
                                        <a href="{{ $responseUrl }}&action=refuse" style="color:#64748b;font-size:13px;text-decoration:underline;">Refuser le devis</a>
                                    </td>
                                </tr>
                                @endif
                            </table>

                            @if($paymentMethodsNote)
                            <p style="margin:20px 0 0;font-size:13px;color:#64748b;line-height:1.55;">
                                {{ $paymentMethodsNote }}
                            </p>
                            @endif

                            <hr style="border:none;border-top:1px solid #e5e7eb;margin:28px 0 20px;">

                            <p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">
                                Une question ? Répondez à cet e-mail ou connectez-vous à votre espace pour suivre l'avancement.
                            </p>
                            <p style="margin:12px 0 0;color:#374151;font-size:14px;line-height:1.5;">
                                Cordialement,<br>
                                <strong>{{ $siteName }}</strong>
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#f8fafc;border-top:1px solid #e5e7eb;padding:20px 32px;text-align:center;">
                            <p style="margin:0 0 4px;color:#6b7280;font-size:12px;">
                                {{ $siteName }}
                                @if($sitePhone) · {{ $sitePhone }}@endif
                                @if($siteEmail) · {{ $siteEmail }}@endif
                            </p>
                            @if($responseUrl)
                            <p style="margin:8px 0 0;color:#9ca3af;font-size:11px;line-height:1.4;">
                                Liens directs : <a href="{{ $responseUrl }}&action=accept" style="color:#6b7280;">Accepter</a> · <a href="{{ $responseUrl }}&action=refuse" style="color:#6b7280;">Refuser</a>
                            </p>
                            @elseif($paymentUrl)
                            <p style="margin:8px 0 0;color:#9ca3af;font-size:11px;line-height:1.4;">
                                Si le bouton ne fonctionne pas : <a href="{{ $paymentUrl }}" style="color:#6b7280;">{{ $paymentUrl }}</a>
                            </p>
                            @endif
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
