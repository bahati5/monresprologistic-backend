<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\ResolvesQuoteAgency;
use App\Http\Controllers\Controller;
use App\Models\QuoteAuditLog;
use App\Models\QuoteEmailTemplate;
use App\Models\Setting;
use App\Support\ShipmentDocumentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteSettingsController extends Controller
{
    use ResolvesQuoteAgency;

    private const CURRENCY_KEYS = [
        'quote_primary_currency',
        'quote_secondary_currency_enabled',
        'quote_secondary_currency',
        'quote_secondary_currency_rate_mode',
        'quote_secondary_currency_rate',
        'quote_scraped_price_to_primary_multiplier',
    ];

    private const FOLLOW_UP_KEYS = [
        'quote_validity_days',
        'quote_reminder_1_delay_days',
        'quote_reminder_2_delay_days',
        'quote_auto_reminders_enabled',
    ];

    public function currency(Request $request): JsonResponse
    {
        $settings = Setting::whereIn('key', self::CURRENCY_KEYS)
            ->pluck('value', 'key');

        $appCurrency = Setting::getValue('currency', '');

        return response()->json([
            'primary_currency' => $settings['quote_primary_currency'] ?? $appCurrency,
            'secondary_currency_enabled' => (bool) ($settings['quote_secondary_currency_enabled'] ?? false),
            'secondary_currency' => $settings['quote_secondary_currency'] ?? '',
            'rate_mode' => $settings['quote_secondary_currency_rate_mode'] ?? 'manual',
            'rate' => (float) ($settings['quote_secondary_currency_rate'] ?? 0),
            'scraped_price_to_primary_multiplier' => (float) ($settings['quote_scraped_price_to_primary_multiplier'] ?? 1),
        ]);
    }

    public function updateCurrency(Request $request): JsonResponse
    {
        $data = $request->validate([
            'primary_currency' => ['required', 'string', 'size:3'],
            'secondary_currency_enabled' => ['required', 'boolean'],
            'secondary_currency' => ['required_if:secondary_currency_enabled,true', 'string', 'size:3'],
            'rate_mode' => ['required_if:secondary_currency_enabled,true', 'string', 'in:manual,auto'],
            'rate' => ['required_if:rate_mode,manual', 'numeric', 'min:0.0001'],
            'scraped_price_to_primary_multiplier' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
        ]);

        Setting::setValue('quote_primary_currency', $data['primary_currency']);
        Setting::setValue('quote_secondary_currency_enabled', $data['secondary_currency_enabled'] ? '1' : '0');
        Setting::setValue('quote_secondary_currency', $data['secondary_currency'] ?? '');
        Setting::setValue('quote_secondary_currency_rate_mode', $data['rate_mode'] ?? 'manual');
        Setting::setValue('quote_secondary_currency_rate', (string) ($data['rate'] ?? 0));
        $mult = isset($data['scraped_price_to_primary_multiplier']) && is_numeric($data['scraped_price_to_primary_multiplier'])
            ? (float) $data['scraped_price_to_primary_multiplier']
            : 1.0;
        if (! is_finite($mult) || $mult <= 0) {
            $mult = 1.0;
        }
        Setting::setValue('quote_scraped_price_to_primary_multiplier', (string) $mult);

        return response()->json(['message' => 'Paramètres devises mis à jour.']);
    }

    public function followUp(Request $request): JsonResponse
    {
        $settings = Setting::whereIn('key', self::FOLLOW_UP_KEYS)
            ->pluck('value', 'key');

        return response()->json([
            'validity_days' => (int) ($settings['quote_validity_days'] ?? 7),
            'reminder_1_delay_days' => (int) ($settings['quote_reminder_1_delay_days'] ?? 2),
            'reminder_2_delay_days' => (int) ($settings['quote_reminder_2_delay_days'] ?? 5),
            'auto_reminders_enabled' => (bool) ($settings['quote_auto_reminders_enabled'] ?? true),
        ]);
    }

    public function updateFollowUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'validity_days' => ['required', 'integer', 'min:1', 'max:90'],
            'reminder_1_delay_days' => ['required', 'integer', 'min:1', 'max:30'],
            'reminder_2_delay_days' => ['required', 'integer', 'min:1', 'max:60'],
            'auto_reminders_enabled' => ['required', 'boolean'],
        ]);

        Setting::setValue('quote_validity_days', (string) $data['validity_days']);
        Setting::setValue('quote_reminder_1_delay_days', (string) $data['reminder_1_delay_days']);
        Setting::setValue('quote_reminder_2_delay_days', (string) $data['reminder_2_delay_days']);
        Setting::setValue('quote_auto_reminders_enabled', $data['auto_reminders_enabled'] ? '1' : '0');

        return response()->json(['message' => 'Paramètres de relance mis à jour.']);
    }

    public function emailTemplates(Request $request): JsonResponse
    {
        $user = $request->user();

        $templates = QuoteEmailTemplate::forAgency($this->quoteAgencyId($request))
            ->orderBy('event')
            ->get();

        return response()->json(['templates' => $templates]);
    }

    public function updateEmailTemplate(Request $request, QuoteEmailTemplate $quoteEmailTemplate): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->quoteAgencyId($request);

        if ($quoteEmailTemplate->agency_id !== $agencyId) {
            abort(403);
        }

        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:60000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $quoteEmailTemplate->update($data);

        return response()->json(['template' => $quoteEmailTemplate->fresh()]);
    }

    /**
     * Génère un aperçu HTML complet de l'e-mail tel qu'il sera reçu par le client,
     * avec des données fictives pour toutes les variables.
     */
    public function previewEmailTemplate(Request $request, QuoteEmailTemplate $quoteEmailTemplate): JsonResponse
    {
        $agencyId = $this->quoteAgencyId($request);
        if ($quoteEmailTemplate->agency_id !== $agencyId) {
            abort(403);
        }

        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:60000'],
        ]);

        $subject = $data['subject'] ?? $quoteEmailTemplate->subject;
        $body = $data['body'] ?? $quoteEmailTemplate->body;

        $doc = ShipmentDocumentSettings::merged();
        $accentColor = $doc['accent_color'] ?? '#073763';
        $logoUrl = $doc['logo_url'] ?? null;
        $siteName = $doc['site_name'] ?? config('app.name', 'Monrespro');
        $siteEmail = $doc['site_email'] ?? '';
        $sitePhone = $doc['phone'] ?? '';

        $sampleVars = [
            'quote_reference' => 'DEV-2026-00042',
            'purchase_id' => '42',
            'client_name' => 'Cédric Ilunga',
            'client_first_name' => 'Cédric',
            'client_email' => 'cedric.ilunga@example.com',
            'total_formatted' => '123,56',
            'quote_total' => '123,56',
            'total_amount' => '123.56',
            'currency' => 'USD',
            'currency_symbol' => '$',
            'total_secondary' => '124 500',
            'secondary_currency' => 'CDF',
            'quote_link' => config('app.frontend_url', config('app.url')) . '/devis/reponse?token=demo-preview-token',
            'response_url' => config('app.frontend_url', config('app.url')) . '/devis/reponse?token=demo-preview-token',
            'payment_url' => config('app.frontend_url', config('app.url')) . '/purchase-orders/42/pay',
            'validity_days' => (string) Setting::getValue('quote_validity_days', '7'),
            'expiry_date' => now()->addDays((int) Setting::getValue('quote_validity_days', '7'))->format('d/m/Y'),
            'expires_at' => now()->addDays((int) Setting::getValue('quote_validity_days', '7'))->format('d/m/Y'),
            'expired_at' => now()->subDay()->format('d/m/Y'),
            'company_phone' => $sitePhone ?: '+243900077784',
            'company_name' => $siteName,
            'site_name' => $siteName,
            'site_email' => $siteEmail ?: 'info@monrespro.com',
            'company_email' => $siteEmail ?: 'info@monrespro.com',
            'estimated_delivery' => '7-10 jours ouvrés (fret aérien depuis Bruxelles)',
            'staff_message' => 'Devis pour votre équipement sportif Decathlon. Poids important, fret aérien recommandé.',
            'payment_methods_note' => 'Moyens de paiement acceptés : MPESA - ORANGE MONEY - AIRTEL MONEY - AFRIMONEY',
            'payment_instructions' => 'Veuillez effectuer le virement sur le compte suivant : BE00 0000 0000 0000',
            'lines_subtotal_formatted' => '99,97 $',
            'service_fee_formatted' => '15,00 $',
            'bank_fee_formatted' => '3,45 $',
            'bank_fee_percentage' => '3',
            'accent_color' => $accentColor,
            'logo_url' => $logoUrl ?? '',
            'articles_summary' => 'Kit haltères 20 kg réglables (×1), Tapis de sol fitness 180×60 cm (×1)',
            'new_request_url' => config('app.frontend_url', config('app.url')) . '/purchase-orders/new',
            'supplier_tracking' => 'AMZ-FR-9876543210',
            'hub_name' => 'Entrepôt Bruxelles',
            'received_weight' => '12.5',
            'amount_paid' => '123,56',
            'reminder_number' => '1',
        ];

        $renderedSubject = $this->replaceVars($subject, $sampleVars);
        $renderedBody = $this->replaceVars($body, $sampleVars);

        $html = view('mail.email-template-preview', [
            'accentColor' => $accentColor,
            'logoUrl' => $logoUrl,
            'siteName' => $siteName,
            'siteEmail' => $sampleVars['site_email'],
            'sitePhone' => $sampleVars['company_phone'],
            'renderedBody' => $renderedBody,
            'eventTitle' => $this->eventTitle($quoteEmailTemplate->event),
        ])->render();

        return response()->json([
            'subject' => $renderedSubject,
            'html' => $html,
        ]);
    }

    private function replaceVars(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }
        return $template;
    }

    private function eventTitle(string $event): string
    {
        return match ($event) {
            'quote_sent' => 'Votre devis est prêt',
            'quote_reminder_1', 'quote_reminder_2' => 'Rappel — Votre devis est en attente',
            'quote_accepted' => 'Devis accepté — Merci !',
            'quote_rejected' => 'Devis refusé',
            'payment_received' => 'Paiement reçu',
            'quote_expired' => 'Votre devis a expiré',
            'order_placed' => 'Vos articles ont été commandés',
            'arrived_at_hub' => 'Colis reçu à l\'entrepôt',
            default => 'Notification',
        };
    }

    public function auditLog(Request $request): JsonResponse
    {
        $user = $request->user();

        $entries = QuoteAuditLog::where('agency_id', $this->quoteAgencyId($request))
            ->with('performer:id,name')
            ->orderByDesc('performed_at')
            ->limit(100)
            ->get();

        return response()->json(['entries' => $entries]);
    }
}
