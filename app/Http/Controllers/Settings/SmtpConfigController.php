<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        foreach ($data as $key => $value) {
            Setting::setValue($key, $value ?? '');
        }

        return response()->json(['message' => 'Configuration SMTP mise à jour.']);
    }
}
