<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
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
            'twilio_enabled' => ['nullable', 'string'],
            'twilio_sid' => ['nullable', 'string', 'max:255'],
            'twilio_token' => ['nullable', 'string', 'max:255'],
            'twilio_number' => ['nullable', 'string', 'max:32'],
            'whatsapp_enabled' => ['nullable', 'string'],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
        ]);

        foreach ($data as $key => $value) {
            Setting::setValue($key, $value ?? '');
        }

        return response()->json(['message' => 'Configuration Twilio/WhatsApp mise à jour.']);
    }
}
