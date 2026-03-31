<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentGatewayController extends Controller
{
    private const KEYS = [
        'paypal_enabled', 'paypal_email', 'paypal_mode', 'paypal_api_key',
        'stripe_enabled', 'stripe_public_key', 'stripe_secret_key', 'stripe_mode',
        'paystack_enabled', 'paystack_public_key', 'paystack_secret_key',
        'wire_transfer_enabled', 'wire_transfer_bank_name', 'wire_transfer_iban',
        'wire_transfer_bic', 'wire_transfer_account_holder',
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
            'paypal_enabled' => ['nullable', 'string'],
            'paypal_email' => ['nullable', 'string', 'max:255'],
            'paypal_mode' => ['nullable', 'string', 'in:sandbox,production'],
            'paypal_api_key' => ['nullable', 'string', 'max:500'],
            'stripe_enabled' => ['nullable', 'string'],
            'stripe_public_key' => ['nullable', 'string', 'max:500'],
            'stripe_secret_key' => ['nullable', 'string', 'max:500'],
            'stripe_mode' => ['nullable', 'string', 'in:test,production'],
            'paystack_enabled' => ['nullable', 'string'],
            'paystack_public_key' => ['nullable', 'string', 'max:500'],
            'paystack_secret_key' => ['nullable', 'string', 'max:500'],
            'wire_transfer_enabled' => ['nullable', 'string'],
            'wire_transfer_bank_name' => ['nullable', 'string', 'max:255'],
            'wire_transfer_iban' => ['nullable', 'string', 'max:64'],
            'wire_transfer_bic' => ['nullable', 'string', 'max:32'],
            'wire_transfer_account_holder' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($data as $key => $value) {
            Setting::setValue($key, $value ?? '');
        }

        return response()->json(['message' => 'Passerelles de paiement mises à jour.']);
    }
}
