<?php

namespace App\Support;

use App\Models\Setting;

/**
 * URL publique du portail (SPA) : e-mails, notifications, liens « payer le devis ».
 *
 * Ordre : FRONTEND_URL (.env) → site_url (paramètres généraux) → APP_URL (dernier recours).
 */
final class FrontendPortalUrl
{
    public static function base(): string
    {
        $fromEnv = trim((string) env('FRONTEND_URL', ''));
        if ($fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }

        $fromSettings = trim((string) Setting::getValue('site_url', ''));
        if ($fromSettings !== '') {
            return rtrim($fromSettings, '/');
        }

        return rtrim((string) config('app.url'), '/');
    }
}
