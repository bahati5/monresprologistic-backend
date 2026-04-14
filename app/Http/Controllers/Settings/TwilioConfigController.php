<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Twilio\TwilioGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwilioConfigController extends Controller
{
    private const KEYS = [
        'twilio_enabled', 'twilio_sid', 'twilio_token', 'twilio_number',
        'whatsapp_enabled', 'whatsapp_number',
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
            'twilio_enabled' => ['nullable'],
            'twilio_sid' => ['nullable', 'string', 'max:255'],
            'twilio_token' => ['nullable', 'string', 'max:255'],
            'twilio_number' => ['nullable', 'string', 'max:32'],
            'whatsapp_enabled' => ['nullable'],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
        ]);

        if (array_key_exists('twilio_token', $data) && ($data['twilio_token'] === null || $data['twilio_token'] === '')) {
            unset($data['twilio_token']);
        }

        if (array_key_exists('twilio_enabled', $data)) {
            $data['twilio_enabled'] = self::normalizeBoolString($data['twilio_enabled']);
        }
        if (array_key_exists('whatsapp_enabled', $data)) {
            $data['whatsapp_enabled'] = self::normalizeBoolString($data['whatsapp_enabled']);
        }

        foreach ($data as $key => $value) {
            Setting::setValue($key, $value ?? '');
        }

        return response()->json(['message' => 'Configuration Twilio/WhatsApp mise à jour.']);
    }

    /**
     * Sans paramètre « to » : vérifie SID + token auprès de l’API Twilio.
     * Avec « to » : envoie un SMS ou un message WhatsApp de test (sans exiger que l’option soit activée).
     */
    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['nullable', 'string', 'max:40'],
            'channel' => ['nullable', 'string', 'in:sms,whatsapp'],
        ]);

        if (! TwilioGateway::hasCredentials()) {
            return response()->json([
                'message' => 'Renseignez le Account SID et l’Auth Token Twilio, puis enregistrez.',
            ], 422);
        }

        $to = isset($validated['to']) ? trim((string) $validated['to']) : '';
        $channel = $validated['channel'] ?? 'sms';

        if ($to === '') {
            try {
                $account = TwilioGateway::verifyCredentials();

                return response()->json([
                    'message' => 'Connexion à Twilio réussie.',
                    'account_status' => $account->status ?? null,
                ]);
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'message' => 'Impossible de joindre Twilio avec ces identifiants.',
                    'error' => $e->getMessage(),
                ], 422);
            }
        }

        $body = $channel === 'whatsapp'
            ? 'MonResPro — test WhatsApp depuis les paramètres.'
            : 'MonResPro — test SMS depuis les paramètres.';

        try {
            if ($channel === 'whatsapp') {
                $from = TwilioGateway::whatsappFromNumber();
                if ($from === '') {
                    return response()->json([
                        'message' => 'Renseignez le numéro WhatsApp expéditeur pour ce test.',
                    ], 422);
                }
                $client = TwilioGateway::makeClient();
                $fromAddr = str_starts_with($from, 'whatsapp:') ? $from : 'whatsapp:'.$from;
                $dest = str_starts_with($to, 'whatsapp:') ? $to : 'whatsapp:'.$to;
                $msg = $client->messages->create($dest, [
                    'from' => $fromAddr,
                    'body' => $body,
                ]);
            } else {
                $from = TwilioGateway::smsFromNumber();
                if ($from === '') {
                    return response()->json([
                        'message' => 'Renseignez le numéro SMS expéditeur pour ce test.',
                    ], 422);
                }
                $msg = TwilioGateway::makeClient()->messages->create($to, [
                    'from' => $from,
                    'body' => $body,
                ]);
            }

            return response()->json([
                'message' => 'Message de test envoyé.',
                'sid' => $msg->sid ?? null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Échec de l’envoi du message de test.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private static function normalizeBoolString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $s = strtolower(trim((string) $value));

        return in_array($s, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }
}
