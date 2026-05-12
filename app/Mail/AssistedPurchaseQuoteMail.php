<?php

namespace App\Mail;

use App\Models\AssistedPurchase;
use App\Models\QuoteEmailTemplate;
use App\Models\QuoteSnapshot;
use App\Models\Setting;
use App\Support\AssistedPurchaseQuotePresentation;
use App\Support\QuoteSnapshotDataNormalizer;
use App\Support\ShipmentDocumentSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssistedPurchaseQuoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $quoteRows;

    public string $clientFirstName;

    public string $linesSubtotalFormatted;

    public string $serviceFeeFormatted;

    public string $bankFeeFormatted;

    public string $bankFeePercentageLabel;

    public string $totalFormatted;

    public string $currency;

    public string $currencySymbol;

    public string $paymentUrl;

    public ?string $paymentMethodsNote;

    public ?string $responseUrl = null;

    public ?array $snapshotData = null;

    /**
     * Contenu HTML du modèle « quote_sent » (corps), variables remplacées. Affiché en tête du mail si non vide.
     */
    public ?string $templateIntroHtml = null;

    public ?string $estimatedDelivery = null;

    public ?string $staffMessage = null;

    public ?string $expiresAt = null;

    public ?string $logoUrl = null;

    public string $siteName = 'Monrespro';

    public string $siteEmail = '';

    public string $sitePhone = '';

    public string $accentColor = '#073763';

    public function __construct(public AssistedPurchase $purchase)
    {
        $this->purchase->loadMissing([
            'user.profile.city',
            'user.profile.state',
            'user.profile.country',
            'operator',
            'items',
        ]);

        $p = AssistedPurchaseQuotePresentation::forPurchase($this->purchase);
        $this->quoteRows = $p['quoteRows'];
        $this->clientFirstName = $p['clientFirstName'];
        $this->linesSubtotalFormatted = $p['linesSubtotalFormatted'];
        $this->serviceFeeFormatted = $p['serviceFeeFormatted'];
        $this->bankFeeFormatted = $p['bankFeeFormatted'];
        $this->bankFeePercentageLabel = $p['bankFeePercentageLabel'];
        $this->totalFormatted = $p['totalFormatted'];
        $this->currency = $p['currency'];
        $docSymbol = trim((string) ($p['doc']['currency_symbol'] ?? ''));
        $this->currencySymbol = $docSymbol !== '' ? $docSymbol : match (strtoupper($this->currency)) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => $this->currency,
        };
        $this->paymentUrl = $p['paymentUrl'];
        $this->paymentMethodsNote = $p['paymentMethodsNote'];

        $latestSnapshot = QuoteSnapshot::where('assisted_purchase_id', $purchase->id)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();

        if ($latestSnapshot) {
            $raw = $latestSnapshot->getRawOriginal('snapshot_data');
            $this->snapshotData = QuoteSnapshotDataNormalizer::toArray($raw ?? $latestSnapshot->snapshot_data);
            $this->estimatedDelivery = $latestSnapshot->estimated_delivery;
            $this->staffMessage = $latestSnapshot->staff_message;
            $this->expiresAt = $latestSnapshot->expires_at?->format('d/m/Y');

            if ($latestSnapshot->response_token) {
                $frontendUrl = config('app.frontend_url', config('app.url'));
                $this->responseUrl = $frontendUrl . '/devis/reponse?token=' . $latestSnapshot->response_token;
            }
        }

        $doc = ShipmentDocumentSettings::merged();
        $this->logoUrl = $doc['logo_url'] ?? null;
        $this->siteName = $doc['site_name'] ?? config('app.name', 'Monrespro');
        $this->siteEmail = $doc['site_email'] ?? '';
        $this->sitePhone = $doc['phone'] ?? '';
        $this->accentColor = $doc['accent_color'] ?? $this->accentColor;

        $this->refreshTemplateIntroHtml();
    }

    /**
     * Recalcule le HTML d'intro depuis le modèle (après modification de snapshot / montants, ex. aperçu).
     */
    public function refreshTemplateIntroHtml(): void
    {
        $template = $this->resolveQuoteEmailTemplate('quote_sent');
        $this->templateIntroHtml = $template && trim((string) $template->body) !== ''
            ? $template->renderBody($this->buildTemplateVariables())
            : null;
    }

    protected function resolveQuoteEmailTemplate(string $event): ?QuoteEmailTemplate
    {
        $agencyId = $this->purchase->user?->agency_id
            ?? $this->purchase->operator?->agency_id;

        if ($agencyId) {
            $tpl = QuoteEmailTemplate::query()
                ->where('event', $event)
                ->where('agency_id', $agencyId)
                ->where('is_active', true)
                ->first();
            if ($tpl) {
                return $tpl;
            }
        }

        return QuoteEmailTemplate::query()
            ->where('event', $event)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, string>
     */
    public function buildTemplateVariables(): array
    {
        $user = $this->purchase->user;
        $validityDays = (int) Setting::getValue('quote_validity_days', '7');

        $secondary = '';
        if (is_array($this->snapshotData) && array_key_exists('total_secondary', $this->snapshotData) && $this->snapshotData['total_secondary'] !== null) {
            $secondary = (string) $this->snapshotData['total_secondary'];
        }
        $secondaryCurrency = is_array($this->snapshotData) ? (string) ($this->snapshotData['secondary_currency'] ?? '') : '';

        $articlesSummary = [];
        foreach ($this->quoteRows as $row) {
            $articlesSummary[] = trim((string) ($row['name'] ?? '')).' × '.(int) ($row['quantity'] ?? 0);
        }

        return [
            'quote_reference' => (string) $this->purchase->id,
            'purchase_id' => (string) $this->purchase->id,
            'client_name' => $user?->name ?? 'Client',
            'client_first_name' => $this->clientFirstName,
            'client_email' => $user?->email ?? '',
            'total_formatted' => $this->totalFormatted,
            'quote_total' => $this->totalFormatted,
            'total_amount' => number_format((float) ($this->purchase->total_amount ?? 0), 2, '.', ''),
            'currency' => $this->currency,
            'currency_symbol' => $this->currencySymbol,
            'total_secondary' => $secondary,
            'secondary_currency' => $secondaryCurrency,
            'quote_link' => $this->responseUrl ?? $this->paymentUrl,
            'response_url' => $this->responseUrl ?? '',
            'payment_url' => $this->paymentUrl,
            'validity_days' => (string) $validityDays,
            'expiry_date' => $this->expiresAt ?? '',
            'expires_at' => $this->expiresAt ?? '',
            'company_phone' => $this->sitePhone,
            'company_name' => $this->siteName,
            'site_name' => $this->siteName,
            'site_email' => $this->siteEmail,
            'company_email' => $this->siteEmail,
            'estimated_delivery' => $this->estimatedDelivery ?? '',
            'staff_message' => $this->staffMessage ?? '',
            'payment_methods_note' => $this->paymentMethodsNote ?? '',
            'payment_instructions' => $this->paymentMethodsNote ?? '',
            'lines_subtotal_formatted' => $this->linesSubtotalFormatted,
            'service_fee_formatted' => $this->serviceFeeFormatted,
            'bank_fee_formatted' => $this->bankFeeFormatted,
            'bank_fee_percentage' => $this->bankFeePercentageLabel,
            'accent_color' => $this->accentColor,
            'logo_url' => $this->logoUrl ?? '',
            'articles_summary' => implode('; ', array_filter($articlesSummary)),
        ];
    }

    public function envelope(): Envelope
    {
        $app = config('app.name', 'Monrespro');

        $template = $this->resolveQuoteEmailTemplate('quote_sent');
        $vars = $this->buildTemplateVariables();

        $subject = $template
            ? $template->renderSubject($vars)
            : $app.' — Votre devis achat assisté #'.$this->purchase->id;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.assisted-purchase-quote',
            text: 'mail.assisted-purchase-quote-text',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $this->purchase->loadMissing([
            'user.profile.city',
            'user.profile.state',
            'user.profile.country',
            'items',
        ]);

        try {
            $present = AssistedPurchaseQuotePresentation::forPurchase($this->purchase);
            $clientRows = AssistedPurchaseQuotePresentation::clientDetailRows($this->purchase);
            $quotedAt = $this->purchase->quoted_at ?? now();

            $qrDataUri = null;
            try {
                $qrDataUri = \App\Support\QrCodeHelper::trackingDataUri(
                    url("/purchase-orders/{$this->purchase->id}"),
                    90
                );
            } catch (\Throwable) {
            }

            $html = view('pdf.assisted-purchase-quote', [
                'purchase' => $this->purchase,
                'present' => $present,
                'snapshot' => $this->snapshotData,
                'clientRows' => $clientRows,
                'quotedAtFormatted' => $quotedAt->timezone(config('app.timezone'))->translatedFormat('d F Y'),
                'responseUrl' => $this->responseUrl,
                'qr_data_uri' => $qrDataUri,
            ])->render();

            $binary = Pdf::loadHTML($html)
                ->setPaper('a4')
                ->setOption('defaultFont', 'DejaVu Sans')
                ->setOption('isRemoteEnabled', true)
                ->output();

            return [
                Attachment::fromData(fn () => $binary, 'devis-achat-assiste-' . $this->purchase->id . '.pdf')
                    ->withMime('application/pdf'),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}
