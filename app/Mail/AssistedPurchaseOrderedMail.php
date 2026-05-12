<?php

namespace App\Mail;

use App\Models\QuoteEmailTemplate;
use App\Support\ShipmentDocumentSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssistedPurchaseOrderedMail extends Mailable
{
    use Queueable, SerializesModels;

    public ?string $logoUrl = null;
    public string $siteName = 'Monrespro';
    public string $siteEmail = '';
    public string $sitePhone = '';
    public string $accentColor = '#073763';
    public string $clientName = 'Client';
    public string $clientFirstName = 'Client';

    /**
     * Contenu HTML du modèle (corps), variables remplacées.
     */
    public ?string $templateIntroHtml = null;

    public function __construct(public AssistedPurchase $purchase)
    {
        $this->purchase->loadMissing('user');
        $this->clientName = $this->purchase->user?->name ?? 'Client';
        
        $firstName = $this->purchase->user?->profile?->first_name;
        if (!$firstName && $this->purchase->user?->name) {
            $parts = explode(' ', $this->purchase->user->name);
            $firstName = $parts[0];
        }
        $this->clientFirstName = $firstName ?: 'Client';

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
        $template = $this->resolveQuoteEmailTemplate('order_placed');

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
        return [
            'quote_reference' => (string) $this->purchase->id,
            'purchase_id' => (string) $this->purchase->id,
            'client_name' => $this->clientName,
            'client_first_name' => $this->clientFirstName,
            'site_name' => $this->siteName,
            'company_phone' => $this->sitePhone,
            'supplier_tracking' => $this->purchase->supplier_tracking_number ?? '',
            'accent_color' => $this->accentColor,
        ];
    }

    public function envelope(): Envelope
    {
        $template = $this->resolveQuoteEmailTemplate('order_placed');
        $subject = $template?->renderSubject($this->buildTemplateVariables())
            ?? 'Vos articles ont été commandés — ' . $this->siteName;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $tracking = $this->purchase->supplier_tracking_number;
        $tracking = is_string($tracking) && trim($tracking) !== '' ? trim($tracking) : null;

        return new Content(
            view: 'mail.assisted-purchase-ordered',
            with: [
                'reference' => (string) $this->purchase->id,
                'tracking' => $tracking,
            ],
        );
    }
}
