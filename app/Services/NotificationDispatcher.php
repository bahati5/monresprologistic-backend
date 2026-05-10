<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Twilio\TwilioGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationDispatcher
{
    public static function dispatch(
        User $user,
        string $eventKey,
        array $variables = [],
        ?string $actionUrl = null,
    ): void {
        $template = NotificationTemplate::where('event_key', $eventKey)
            ->where('is_active', true)
            ->first();

        $title = $template?->title ?? self::defaultTitle($eventKey);
        $body = $template?->body ?? self::defaultBody($eventKey);

        $title = self::replacePlaceholders($title, $variables);
        $body = self::replacePlaceholders($body, $variables);

        $channels = $template?->channels ?? ['in_app'];
        if (is_string($channels)) {
            $channels = explode(',', $channels);
        }

        foreach ($channels as $channel) {
            $channel = trim($channel);
            match ($channel) {
                'in_app' => self::sendInApp($user, $eventKey, $title, $body, $variables, $actionUrl),
                'sms' => self::sendSms($user, $body),
                'email' => self::sendEmail($user, $title, $body),
                'whatsapp' => self::sendWhatsApp($user, $body),
                default => null,
            };
        }
    }

    private static function sendInApp(
        User $user,
        string $channel,
        string $title,
        string $body,
        array $data,
        ?string $actionUrl,
    ): void {
        Notification::create([
            'user_id' => $user->id,
            'type' => 'in_app',
            'channel' => $channel,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'action_url' => $actionUrl,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    private static function sendSms(User $user, string $body): void
    {
        $phone = $user->phone ?? $user->profile?->phone ?? null;
        if (! $phone || ! TwilioGateway::smsEnabled()) {
            return;
        }

        try {
            TwilioGateway::sendSms($phone, $body);

            Notification::create([
                'user_id' => $user->id,
                'type' => 'sms',
                'channel' => 'twilio_sms',
                'title' => 'SMS',
                'body' => $body,
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("SMS failed for user {$user->id}: {$e->getMessage()}");

            Notification::create([
                'user_id' => $user->id,
                'type' => 'sms',
                'channel' => 'twilio_sms',
                'title' => 'SMS',
                'body' => $body,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private static function sendEmail(User $user, string $title, string $body): void
    {
        $email = $user->email;
        if (! $email) {
            return;
        }

        try {
            Mail::raw($body, function ($message) use ($email, $title) {
                $message->to($email)->subject($title);
            });

            Notification::create([
                'user_id' => $user->id,
                'type' => 'email',
                'channel' => 'smtp',
                'title' => $title,
                'body' => $body,
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Email failed for user {$user->id}: {$e->getMessage()}");

            Notification::create([
                'user_id' => $user->id,
                'type' => 'email',
                'channel' => 'smtp',
                'title' => $title,
                'body' => $body,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private static function sendWhatsApp(User $user, string $body): void
    {
        $phone = $user->phone ?? $user->profile?->phone ?? null;
        if (! $phone || ! TwilioGateway::whatsappEnabled()) {
            return;
        }

        try {
            TwilioGateway::sendWhatsApp($phone, $body);

            Notification::create([
                'user_id' => $user->id,
                'type' => 'whatsapp',
                'channel' => 'twilio_whatsapp',
                'title' => 'WhatsApp',
                'body' => $body,
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("WhatsApp failed for user {$user->id}: {$e->getMessage()}");
        }
    }

    private static function replacePlaceholders(string $text, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $text = str_replace("{{{$key}}}", (string) $value, $text);
        }

        return $text;
    }

    private static function defaultTitle(string $eventKey): string
    {
        return match ($eventKey) {
            'shipment.status_changed' => 'Mise à jour de votre expédition',
            'shipment.signed_form_archived' => 'Formulaire signé archivé',
            'assisted_purchase.status_changed' => 'Mise à jour de votre achat assisté',
            'assisted_purchase.quote_expiring_soon' => 'Votre devis expire bientôt',
            'pickup.status_changed' => 'Mise à jour ramassage/livraison',
            'pickup.assigned_to_driver' => 'Nouveau ramassage assigné',
            'pickup.en_route' => 'Votre chauffeur est en route',
            'refund.status_changed' => 'Mise à jour de votre remboursement',
            'refund.client_requested' => 'Nouvelle demande de remboursement',
            'refund.large_amount_pending' => 'Remboursement important en attente',
            'quote.ready' => 'Votre devis est prêt',
            'quote.expiring' => 'Votre devis expire bientôt',
            'payment.confirmed' => 'Paiement confirmé',
            'draft.expiring_soon' => 'Brouillon bientôt expiré',
            default => 'Notification Monrespro',
        };
    }

    private static function defaultBody(string $eventKey): string
    {
        return match ($eventKey) {
            'shipment.status_changed' => 'Votre colis {{tracking}} est maintenant : {{status}}.',
            'shipment.signed_form_archived' => 'Le formulaire signé de votre colis {{tracking}} a bien été archivé.',
            'assisted_purchase.status_changed' => 'Votre demande d\'achat assisté est maintenant : {{status}}.',
            'assisted_purchase.quote_expiring_soon' => 'Votre devis expire dans {{hours_left}}h. Acceptez ou refusez avant expiration.',
            'pickup.status_changed' => 'Votre ramassage/livraison est maintenant : {{status}}.',
            'pickup.assigned_to_driver' => 'Un ramassage vous a été assigné (client : {{client_nom}}). Statut : {{status}}.',
            'pickup.en_route' => 'Votre chauffeur Monrespro est en route pour livrer votre colis. Référence : {{reference}}.',
            'refund.status_changed' => 'Votre demande de remboursement est maintenant : {{status}}.',
            'refund.client_requested' => '{{client_nom}} a soumis une demande ({{reference_code}}, {{amount}} {{currency}}).',
            'refund.large_amount_pending' => 'Un remboursement de {{amount}} {{currency}} est en attente d\'approbation (dossier {{reference_code}}).',
            'quote.ready' => 'Un devis est disponible pour votre demande. Connectez-vous pour le consulter.',
            'quote.expiring' => 'Votre devis expire demain. Acceptez ou refusez avant expiration.',
            'payment.confirmed' => 'Votre paiement de {{amount}} a été confirmé.',
            'draft.expiring_soon' => 'Votre brouillon « {{form_type_label}} » expire dans {{expires_in_days}} jours. Pensez à le compléter ou il sera supprimé.',
            default => 'Vous avez une nouvelle notification.',
        };
    }
}
