<?php

namespace App\Services\Twilio;

use App\Models\Setting;
use Twilio\Rest\Client;

class TwilioGateway
{
    public static function accountSid(): string
    {
        return trim((string) Setting::getValue('twilio_sid', ''));
    }

    public static function authToken(): string
    {
        return (string) (Setting::getValue('twilio_token', '') ?? '');
    }

    public static function smsFromNumber(): string
    {
        return trim((string) Setting::getValue('twilio_number', ''));
    }

    public static function whatsappFromNumber(): string
    {
        return trim((string) Setting::getValue('whatsapp_number', ''));
    }

    public static function smsEnabled(): bool
    {
        $v = strtolower(trim((string) Setting::getValue('twilio_enabled', '')));

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function whatsappEnabled(): bool
    {
        $v = strtolower(trim((string) Setting::getValue('whatsapp_enabled', '')));

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function hasCredentials(): bool
    {
        return self::accountSid() !== '' && self::authToken() !== '';
    }

    public static function makeClient(): Client
    {
        if (! self::hasCredentials()) {
            throw new \InvalidArgumentException('Twilio Account SID et Auth Token sont requis.');
        }

        return new Client(self::accountSid(), self::authToken());
    }

    /**
     * Vérifie les identifiants auprès de l’API Twilio (sans envoyer de message).
     */
    public static function verifyCredentials(): object
    {
        $client = self::makeClient();
        $sid = self::accountSid();

        return $client->api->v2010->accounts($sid)->fetch();
    }

    public static function sendSms(string $to, string $body): object
    {
        if (! self::smsEnabled()) {
            throw new \RuntimeException('L’envoi SMS Twilio est désactivé dans les paramètres.');
        }
        $from = self::smsFromNumber();
        if ($from === '') {
            throw new \RuntimeException('Numéro d’expéditeur SMS Twilio non configuré.');
        }

        return self::makeClient()->messages->create($to, [
            'from' => $from,
            'body' => $body,
        ]);
    }

    public static function sendWhatsApp(string $to, string $body): object
    {
        if (! self::whatsappEnabled()) {
            throw new \RuntimeException('WhatsApp Twilio est désactivé dans les paramètres.');
        }
        $fromRaw = self::whatsappFromNumber();
        if ($fromRaw === '') {
            throw new \RuntimeException('Numéro WhatsApp expéditeur non configuré.');
        }

        $from = str_starts_with($fromRaw, 'whatsapp:') ? $fromRaw : 'whatsapp:'.$fromRaw;
        $dest = str_starts_with($to, 'whatsapp:') ? $to : 'whatsapp:'.$to;

        return self::makeClient()->messages->create($dest, [
            'from' => $from,
            'body' => $body,
        ]);
    }
}
