<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Devis expiré #{{ $purchaseId }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f6f8;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

                    {{-- Header --}}
                    <tr>
                        <td style="background-color:{{ $accentColor }};padding:28px 32px;text-align:center;">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $siteName }}" style="max-height:48px;max-width:200px;margin-bottom:8px;">
                            @else
                                <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;letter-spacing:-0.5px;">{{ $siteName }}</h1>
                            @endif
                        </td>
                    </tr>

                    {{-- Content --}}
                    <tr>
                        <td style="padding:32px;">
                            <h2 style="margin:0 0 20px;color:{{ $accentColor }};font-size:22px;font-weight:700;">
                                Votre devis a expiré
                            </h2>

                            @if(!empty($templateIntroHtml))
                            <div style="margin:0 0 24px;color:#374151;font-size:15px;line-height:1.6;">
                                {!! $templateIntroHtml !!}
                            </div>
                            @else
                            <p style="margin:0 0 16px;color:#374151;font-size:15px;line-height:1.6;">
                                Bonjour <strong>{{ $clientName }}</strong>,
                            </p>
                            <p style="margin:0 0 24px;color:#374151;font-size:15px;line-height:1.6;">
                                Votre <strong>devis n° {{ $purchaseId }}</strong> d'un montant de <strong>{{ $quoteTotal }} {{ $currency }}</strong> a expiré le <strong>{{ $expiredAt }}</strong> et a été automatiquement annulé.
                            </p>
                            @endif

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:24px;">
                                <tr>
                                    <td style="background-color:#f0f4f8;border-left:4px solid {{ $accentColor }};padding:12px 16px;border-radius:0 6px 6px 0;">
                                        <p style="margin:0;color:#374151;font-size:13px;">
                                            Si vous êtes toujours intéressé(e) par ces articles, vous pouvez effectuer une nouvelle demande d'achat assisté depuis votre espace client ou en nous contactant directement.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            @if($newRequestUrl)
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:28px 0 16px;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $newRequestUrl }}" style="display:inline-block;background-color:{{ $accentColor }};color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:14px 32px;border-radius:8px;letter-spacing:0.3px;">
                                            Effectuer une nouvelle demande
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <hr style="border:none;border-top:1px solid #e5e7eb;margin:28px 0 20px;">

                            <p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">
                                Nous restons à votre disposition pour toute question.
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
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
