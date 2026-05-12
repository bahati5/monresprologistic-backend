<?php

namespace App\Mail;

use App\Models\AssistedPurchase;
use App\Models\QuoteEmailTemplate;
use App\Models\Setting;
use App\Support\ShipmentDocumentSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteExpiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $clientName;
    public int $purchaseId;
    public string $quoteTotal;
    public string $currency;
    public string $expiredAt;
    public ?string $newRequestUrl;

    /**
     * Contenu HTML du modèle (corps), variables remplacées.
     */
    public ?string $templateIntroHtml = null;

    public ?string $logoUrl = null;
    public string $siteName = 'Monrespro';
    public string $siteEmail = '';
    public string $sitePhone = '';
    public string $accentColor = '#073763';

    public function __construct(public AssistedPurchase $purchase)
    {
        $user = $purchase->user;
        $this->clientName = $user?->name ?? 'Client';
        $this->purchaseId = $purchase->id;

        $snapshot = QuoteSnapshot::where('assisted_purchase_id', $purchase->id)
            ->orderByDesc('version')
            ->first();

        $this->quoteTotal = $snapshot
            ? number_format((float) $snapshot->total_primary, 2, ',', ' ')
            : number_format((float) ($purchase->total_amount ?? 0), 2, ',', ' ');

        $this->currency = $snapshot?->primary_currency ?? 'USD';
        $this->expiredAt = ($purchase->quote_expires_at ?? now())->format('d/m/Y');
        $this->newRequestUrl = config('app.frontend_url', config('app.url')) . '/purchase-orders/new';

        $doc = ShipmentDocumentSettings::merged();
        $this->logoUrl = $doc['logo_url'] ?? null;
        $this->siteName = $doc['site_name'] ?? config('app.name', 'Monrespro');
        $this->siteEmail = $doc['site_email'] ?? '';
        $this->sitePhone = $doc['phone'] ?? '';
        $this->accentColor = $doc['accent_color'] ?? $this->accentColor;

        $this->refreshTemplateIntroHtml();
    }

    public function refreshTemplateIntroHtml(): void
    {
        $template = $this->resolveQuoteEmailTemplate('quote_expired');

        if ($template && trim((string) $template->body) !== '') {
            $this->templateIntroHtml = $template->renderBody($this->buildTemplateVariables());
        }
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

    public function buildTemplateVariables(): array
    {
        $firstName = 'Client';
        if ($this->purchase->user) {
            $firstName = $this->purchase->user->profile?->first_name;
            if (!$firstName && $this->purchase->user->name) {
                $parts = explode(' ', $this->purchase->user->name);
                $firstName = $parts[0];
            }
        }

        return [
            'quote_reference' => (string) $this->purchaseId,
            'purchase_id' => (string) $this->purchaseId,
            'client_name' => $this->clientName,
            'client_first_name' => $firstName ?: 'Client',
            'quote_total' => $this->quoteTotal,
            'total_formatted' => $this->quoteTotal,
            'currency' => $this->currency,
            'expiry_date' => $this->expiredAt,
            'expired_at' => $this->expiredAt,
            'company_phone' => $this->sitePhone,
            'site_name' => $this->siteName,
            'site_email' => $this->siteEmail,
            'accent_color' => $this->accentColor,
            'new_request_url' => $this->newRequestUrl ?? '',
        ];
    }

    public function envelope(): Envelope
    {
        $template = $this->resolveQuoteEmailTemplate('quote_expired');

        $subject = $template?->renderSubject($this->buildTemplateVariables())
            ?? 'Votre devis a expiré';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.quote-expired');
    }
}
