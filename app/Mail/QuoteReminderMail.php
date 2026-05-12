<?php

namespace App\Mail;

use App\Models\AssistedPurchase;
use App\Models\QuoteEmailTemplate;
use App\Models\Setting;
use App\Support\QuoteSnapshotDataNormalizer;
use App\Support\ShipmentDocumentSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $clientName;
    public int $purchaseId;
    public string $quoteTotal;
    public string $currency;
    public ?string $expiresAt;
    public ?string $responseUrl;
    public int $reminderNumber;

    /**
     * Contenu HTML du modèle (corps), variables remplacées.
     */
    public ?string $templateIntroHtml = null;

    public ?string $logoUrl = null;
    public string $siteName = 'Monrespro';
    public string $siteEmail = '';
    public string $sitePhone = '';
    public string $accentColor = '#073763';

    public function __construct(
        public AssistedPurchase $purchase,
        int $reminderNumber,
    ) {
        $this->reminderNumber = $reminderNumber;
        $this->purchaseId = $purchase->id;

        $user = $purchase->user;
        $this->clientName = $user?->name ?? 'Client';

        $snapshot = QuoteSnapshot::where('assisted_purchase_id', $purchase->id)
            ->orderByDesc('version')
            ->first();

        $this->quoteTotal = $snapshot
            ? number_format((float) $snapshot->total_primary, 2, ',', ' ')
            : number_format((float) ($purchase->total_amount ?? 0), 2, ',', ' ');

        $this->currency = $snapshot?->primary_currency ?? 'USD';
        $this->expiresAt = $purchase->quote_expires_at?->format('d/m/Y');

        $this->responseUrl = $snapshot?->response_token
            ? config('app.frontend_url', config('app.url')) . '/devis/reponse?token=' . $snapshot->response_token
            : null;

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
        $event = $this->reminderNumber === 1 ? 'quote_reminder_1' : 'quote_reminder_2';
        $template = $this->resolveQuoteEmailTemplate($event);

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
        $validityDays = (int) Setting::getValue('quote_validity_days', '7');

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
            'quote_link' => $this->responseUrl ?? '',
            'validity_days' => (string) $validityDays,
            'expiry_date' => $this->expiresAt ?? '',
            'expires_at' => $this->expiresAt ?? '',
            'company_phone' => $this->sitePhone,
            'site_name' => $this->siteName,
            'site_email' => $this->siteEmail,
            'accent_color' => $this->accentColor,
            'reminder_number' => (string) $this->reminderNumber,
        ];
    }

    public function envelope(): Envelope
    {
        $event = $this->reminderNumber === 1 ? 'quote_reminder_1' : 'quote_reminder_2';
        $template = $this->resolveQuoteEmailTemplate($event);

        $subject = $template?->renderSubject($this->buildTemplateVariables())
            ?? "Rappel #{$this->reminderNumber} — Votre devis attend votre réponse";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.quote-reminder');
    }
}
