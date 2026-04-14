<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Applique la configuration SMTP stockée en base (table settings) au mailer Laravel par défaut.
 * Si smtp_host est vide, la configuration .env / config/mail.php reste inchangée.
 */
class DynamicMailSettings
{
    /**
     * @return array<string, mixed>|null
     */
    public static function buildSmtpMailerConfigFromDatabase(): ?array
    {
        $host = trim((string) Setting::getValue('smtp_host', ''));
        if ($host === '') {
            return null;
        }

        $port = (int) (Setting::getValue('smtp_port', '') ?: 587);
        if ($port <= 0) {
            $port = 587;
        }

        $security = strtolower(trim((string) Setting::getValue('smtp_security', 'tls')));
        if (! in_array($security, ['tls', 'ssl', 'none'], true)) {
            $security = 'tls';
        }

        $user = trim((string) Setting::getValue('smtp_user', ''));
        $password = (string) (Setting::getValue('smtp_password', '') ?? '');

        $scheme = null;
        $autoTls = true;
        if ($security === 'ssl') {
            $scheme = 'smtps';
            if ($port === 587) {
                $port = 465;
            }
        } elseif ($security === 'none') {
            $scheme = 'smtp';
            $autoTls = false;
        }

        $local = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return array_filter([
            'transport' => 'smtp',
            'scheme' => $scheme,
            'url' => null,
            'host' => $host,
            'port' => $port,
            'username' => $user !== '' ? $user : null,
            'password' => $password !== '' ? $password : null,
            'timeout' => null,
            'local_domain' => $local,
            'auto_tls' => $autoTls,
        ], fn ($v) => $v !== null);
    }

    public static function applyFromDatabaseIfConfigured(): void
    {
        $mailer = self::buildSmtpMailerConfigFromDatabase();
        if ($mailer === null) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => $mailer,
        ]);

        $fromEmail = trim((string) Setting::getValue('smtp_from_email', ''));
        $fromName = trim((string) Setting::getValue('smtp_from_name', ''));

        if ($fromEmail !== '') {
            config([
                'mail.from.address' => $fromEmail,
                'mail.from.name' => $fromName !== '' ? $fromName : $fromEmail,
            ]);
        } elseif ($fromName !== '') {
            config(['mail.from.name' => $fromName]);
        }
    }
}
