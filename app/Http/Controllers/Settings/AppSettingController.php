<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\ShipmentDocumentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSettingController extends Controller
{
    private const IDENTITY_KEYS = [
        'site_name', 'site_url', 'site_email', 'nit', 'phone_fixed',
        'phone_mobile', 'address', 'country', 'country_id', 'city', 'zip_code',
    ];

    private const LOCKER_KEYS = [
        'locker_address_template', 'locker_mode', 'locker_digits', 'locker_prefix',
    ];

    private const ACCOUNT_KEYS = [
        'auto_verification', 'registration_enabled', 'admin_notification_on_signup',
    ];

    private const GENERAL_KEYS = [
        'timezone', 'language', 'currency', 'currency_symbol',
        'symbol_position', 'decimals', 'number_format',
        'extra_languages', 'custom_currencies',
    ];

    private const SHIPMENT_CONFIG_KEYS = [
        'tracking_prefix', 'tracking_number_length',
        'volumetric_divisor',
        'default_insurance_pct', 'default_customs_duty_pct', 'default_tax_pct',
    ];

    /** Autres clés métier présentes en base (documents, etc.) — pour ne pas les « perdre » côté UI. */
    private const EXTRA_APP_KEYS = [
        'invoice_terms', 'signing_company', 'signing_customer',
    ];

    /**
     * Ne jamais renvoyer ces clés sur GET /settings/app (secrets / clés API).
     */
    private const SENSITIVE_KEYS_NEVER_EXPOSE = [
        'smtp_password',
        'twilio_token',
        'stripe_secret_key',
        'paystack_secret_key',
        'paypal_api_key',
    ];

    public function edit(): JsonResponse
    {
        $allKeys = array_unique(array_merge(
            self::IDENTITY_KEYS,
            self::LOCKER_KEYS,
            self::ACCOUNT_KEYS,
            self::GENERAL_KEYS,
            self::SHIPMENT_CONFIG_KEYS,
            self::EXTRA_APP_KEYS,
            ['hub_brand_name']
        ));

        $fromDb = Setting::query()->pluck('value', 'key')->all();

        foreach (self::SENSITIVE_KEYS_NEVER_EXPOSE as $secretKey) {
            unset($fromDb[$secretKey]);
        }

        $settings = $fromDb;

        foreach ($allKeys as $key) {
            if (! array_key_exists($key, $settings)) {
                $settings[$key] = '';
            }
        }

        $logoPath = $fromDb['logo_path'] ?? null;
        $faviconPath = $fromDb['favicon_path'] ?? null;
        $settings['logo_url'] = $logoPath ? ShipmentDocumentSettings::publicStorageUrl($logoPath) : null;
        $settings['favicon_url'] = $faviconPath ? ShipmentDocumentSettings::publicStorageUrl($faviconPath) : null;

        unset($settings['logo_path'], $settings['favicon_path']);

        if (($settings['hub_brand_name'] ?? '') === '') {
            $settings['hub_brand_name'] = 'Monrespro';
        }

        ksort($settings);

        return response()->json([
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_name' => ['nullable', 'string', 'max:255'],
            'site_url' => ['nullable', 'string', 'max:500'],
            'site_email' => ['nullable', 'email', 'max:255'],
            'nit' => ['nullable', 'string', 'max:64'],
            'phone_fixed' => ['nullable', 'string', 'max:32'],
            'phone_mobile' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:100'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'city' => ['nullable', 'string', 'max:100'],
            'zip_code' => ['nullable', 'string', 'max:16'],
            'hub_brand_name' => ['nullable', 'string', 'max:255'],
            'locker_address_template' => ['nullable', 'string', 'max:5000'],
            'locker_mode' => ['nullable', 'string', 'in:random,sequential'],
            'locker_digits' => ['nullable', 'integer', 'min:2', 'max:10'],
            'locker_prefix' => ['nullable', 'string', 'max:16'],
            'auto_verification' => ['nullable', 'string'],
            'registration_enabled' => ['nullable', 'string'],
            'admin_notification_on_signup' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'in:fr'],
            'currency' => ['nullable', 'string', 'max:8'],
            'currency_symbol' => ['nullable', 'string', 'max:8'],
            'symbol_position' => ['nullable', 'string', 'in:prefix,suffix'],
            'decimals' => ['nullable', 'integer', 'min:0', 'max:4'],
            'number_format' => ['nullable', 'string', 'max:16'],
            'extra_languages' => ['nullable', 'string', 'max:10000'],
            'custom_currencies' => ['nullable', 'string', 'max:10000'],
            'tracking_prefix' => ['nullable', 'string', 'max:16'],
            'tracking_number_length' => ['nullable', 'integer', 'min:4', 'max:32'],
            'volumetric_divisor' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'default_insurance_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_customs_duty_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_tax_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'invoice_terms' => ['nullable', 'string', 'max:10000'],
            'signing_company' => ['nullable', 'string', 'max:255'],
            'signing_customer' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['country_id'] ?? null)) {
            $c = \App\Models\Country::query()->find((int) $data['country_id']);
            if ($c) {
                $data['country'] = $c->name;
            }
        }

        foreach ($data as $key => $value) {
            Setting::setValue($key, $value === null ? '' : (string) $value);
        }

        return response()->json(['message' => 'Paramètres enregistrés.']);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
        ]);

        $path = $request->file('logo')->store('branding', 'public');
        Setting::setValue('logo_path', $path);

        return response()->json(['message' => 'Logo mis à jour.']);
    }

    public function uploadFavicon(Request $request): JsonResponse
    {
        $request->validate([
            'favicon' => ['required', 'image', 'mimes:png,ico', 'max:512'],
        ]);

        $path = $request->file('favicon')->store('branding', 'public');
        Setting::setValue('favicon_path', $path);

        return response()->json(['message' => 'Favicon mis à jour.']);
    }
}
