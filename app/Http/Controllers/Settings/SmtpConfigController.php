<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\DynamicMailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SmtpConfigController extends Controller
{
    private const KEYS = [
        'smtp_host', 'smtp_port', 'smtp_security', 'smtp_user',
        'smtp_password', 'smtp_from_email', 'smtp_from_name',
    ];

    public function index(): JsonResponse
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $settings[$key] = Setting::getValue($key, '');
        }

        return response()->json([
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'string', 'max:8'],
            'smtp_security' => ['nullable', 'string', 'in:tls,ssl,none'],
            'smtp_user' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_from_email' => ['nullable', 'string', 'max:255'],
            'smtp_from_name' => ['nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('smtp_password', $data) && ($data['smtp_password'] === null || $data['smtp_password'] === '')) {
            unset($data['smtp_password']);
        }

        foreach ($data as $key => $value) {
            Setting::setValue($key, $value ?? '');
        }

        return response()->json(['message' => 'Configuration SMTP mise à jour.']);
    }

    /**
     * Envoie un e-mail de test en utilisant la configuration SMTP actuellement enregistrée.
     */
    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email', 'max:255'],
        ]);

        $mailerConfig = DynamicMailSettings::buildSmtpMailerConfigFromDatabase();
        if ($mailerConfig === null) {
            return response()->json([
                'message' => 'Serveur SMTP non configuré : renseignez au moins l’hôte (smtp_host) et enregistrez.',
            ], 422);
        }

        config(['mail.mailers._monrespro_smtp_test' => $mailerConfig]);

        $fromEmail = trim((string) Setting::getValue('smtp_from_email', ''));
        $fromName = trim((string) Setting::getValue('smtp_from_name', ''));

        try {
            Mail::mailer('_monrespro_smtp_test')->raw(
                "Ceci est un e-mail de test envoyé depuis les paramètres MonResPro.\n\n".now()->toIso8601String(),
                function ($message) use ($validated, $fromEmail, $fromName) {
                    $message->to($validated['to'])
                        ->subject('MonResPro — test SMTP');
                    if ($fromEmail !== '') {
                        $message->from($fromEmail, $fromName !== '' ? $fromName : $fromEmail);
                    }
                }
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Échec de l’envoi de l’e-mail de test.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['message' => 'E-mail de test envoyé.']);
    }
}
