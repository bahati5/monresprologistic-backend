<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\Setting;
use App\Support\ShipmentDocumentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSettingController extends Controller
{
    private const IDENTITY_KEYS = [
        'site_name', 'site_url', 'site_email', 'nit', 'phone_fixed',
        'phone_mobile', 'phone_fixed_secondary', 'phone_mobile_secondary',
        'address', 'country', 'country_id', 'state_id', 'city_id', 'city', 'zip_code',
    ];

    private const LOCKER_KEYS = [
        'locker_address_template', 'locker_mode', 'locker_digits', 'locker_prefix',
        'locker_code_format', 'locker_next_seq', 'locker_seq_pad',
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
        'shipment_tracking_format', 'shipment_tracking_next_seq', 'shipment_tracking_seq_pad',
        'volumetric_divisor',
        'billable_weight_rule',
        'draft_shipment_expiry_days',
        'default_insurance_pct', 'default_customs_duty_pct', 'default_tax_pct',
        'shipment_invoice_format', 'shipment_invoice_prefix', 'shipment_invoice_seq_pad', 'shipment_invoice_next_seq',
    ];

    private const NUMBERING_KEYS = [
        'finance_invoice_format', 'finance_invoice_prefix', 'finance_invoice_seq_pad', 'finance_invoice_next_seq',
        'prealert_reference_format', 'prealert_reference_prefix', 'prealert_reference_seq_pad', 'prealert_next_seq',
        'purchase_order_reference_format', 'purchase_order_reference_prefix', 'purchase_order_reference_seq_pad', 'purchase_order_next_seq',
        'customer_package_reference_format', 'customer_package_reference_prefix', 'customer_package_reference_seq_pad', 'customer_package_next_seq',
    ];

    private const DRAFT_KEYS = [
        'draft_max_per_type',
        'draft_client_expiry_days',
        'draft_staff_expiry_days',
        'draft_autosave_interval_seconds',
    ];

    private const WORKFLOW_KEYS = [
        'quote_expiry_hours',
        'prealert_expiry_days',
        'weight_discrepancy_threshold_pct',
        'sav_response_target_hours',
        'refund_threshold_operator',
        'refund_threshold_agency_admin',
        'default_quote_currency',
    ];

    private const EXTRA_APP_KEYS = [
        'invoice_terms', 'signing_company', 'signing_customer',
        'default_company_coverage_amount',
        'show_sidebar_brand_with_logo',
        'odoo_invoice_sync_trigger',
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
            self::NUMBERING_KEYS,
            self::WORKFLOW_KEYS,
            self::DRAFT_KEYS,
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
        $settings['logo_url'] = $logoPath ? ShipmentDocumentSettings::publicStorageWebPath($logoPath) : null;
        $settings['favicon_url'] = $faviconPath ? ShipmentDocumentSettings::publicStorageWebPath($faviconPath) : null;

        unset($settings['logo_path'], $settings['favicon_path']);

        if (($settings['show_sidebar_brand_with_logo'] ?? '') === '') {
            $settings['show_sidebar_brand_with_logo'] = '1';
        }

        $countryId = (int) ($settings['country_id'] ?? 0);
        $settings['country_iso2'] = '';
        if ($countryId > 0) {
            $iso = Country::query()->whereKey($countryId)->value('iso2');
            if ($iso !== null && $iso !== '') {
                $settings['country_iso2'] = strtoupper(trim((string) $iso));
            }
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
            'phone_fixed' => ['nullable', 'string', 'max:64'],
            'phone_mobile' => ['nullable', 'string', 'max:64'],
            'phone_fixed_secondary' => ['nullable', 'string', 'max:64'],
            'phone_mobile_secondary' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:100'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'city' => ['nullable', 'string', 'max:100'],
            'zip_code' => ['nullable', 'string', 'max:16'],
            'hub_brand_name' => ['nullable', 'string', 'max:255'],
            'locker_address_template' => ['nullable', 'string', 'max:5000'],
            'locker_mode' => ['nullable', 'string', 'in:random,sequential'],
            'locker_digits' => ['nullable', 'integer', 'min:2', 'max:10'],
            'locker_prefix' => ['nullable', 'string', 'max:16'],
            'locker_code_format' => ['nullable', 'string', 'max:120'],
            'locker_next_seq' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'locker_seq_pad' => ['nullable', 'integer', 'min:1', 'max:12'],
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
            'shipment_tracking_format' => ['nullable', 'string', 'max:120'],
            'shipment_tracking_next_seq' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'shipment_tracking_seq_pad' => ['nullable', 'integer', 'min:1', 'max:12'],
            'volumetric_divisor' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'billable_weight_rule' => ['nullable', 'string', 'in:max,min,real,volumetric'],
            'draft_shipment_expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'default_insurance_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_customs_duty_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_tax_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'shipment_invoice_format' => ['nullable', 'string', 'max:120'],
            'shipment_invoice_prefix' => ['nullable', 'string', 'max:32'],
            'shipment_invoice_seq_pad' => ['nullable', 'integer', 'min:1', 'max:12'],
            'shipment_invoice_next_seq' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'finance_invoice_format' => ['nullable', 'string', 'max:120'],
            'finance_invoice_prefix' => ['nullable', 'string', 'max:32'],
            'finance_invoice_seq_pad' => ['nullable', 'integer', 'min:1', 'max:12'],
            'finance_invoice_next_seq' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'prealert_reference_format' => ['nullable', 'string', 'max:120'],
            'prealert_reference_prefix' => ['nullable', 'string', 'max:32'],
            'prealert_reference_seq_pad' => ['nullable', 'integer', 'min:1', 'max:12'],
            'prealert_next_seq' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'purchase_order_reference_format' => ['nullable', 'string', 'max:120'],
            'purchase_order_reference_prefix' => ['nullable', 'string', 'max:32'],
            'purchase_order_reference_seq_pad' => ['nullable', 'integer', 'min:1', 'max:12'],
            'purchase_order_next_seq' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'customer_package_reference_format' => ['nullable', 'string', 'max:120'],
            'customer_package_reference_prefix' => ['nullable', 'string', 'max:32'],
            'customer_package_reference_seq_pad' => ['nullable', 'integer', 'min:1', 'max:12'],
            'customer_package_next_seq' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'invoice_terms' => ['nullable', 'string', 'max:10000'],
            'signing_company' => ['nullable', 'string', 'max:255'],
            'signing_customer' => ['nullable', 'string', 'max:255'],
            'default_company_coverage_amount' => ['nullable', 'numeric', 'min:0'],
            'show_sidebar_brand_with_logo' => ['nullable', 'string', 'in:0,1'],
            'odoo_invoice_sync_trigger' => ['nullable', 'string', 'in:on_delivered,on_invoice_accounting'],
            'quote_expiry_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'prealert_expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'weight_discrepancy_threshold_pct' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'sav_response_target_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'refund_threshold_operator' => ['nullable', 'numeric', 'min:0'],
            'refund_threshold_agency_admin' => ['nullable', 'numeric', 'min:0'],
            'default_quote_currency' => ['nullable', 'string', 'max:8'],
            'draft_max_per_type' => ['nullable', 'integer', 'min:1', 'max:50'],
            'draft_client_expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'draft_staff_expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'draft_autosave_interval_seconds' => ['nullable', 'integer', 'min:5', 'max:300'],
        ]);

        if (! empty($data['city_id'] ?? null)) {
            $city = City::query()->with('country')->find((int) $data['city_id']);
            if ($city) {
                $data['city'] = $city->name;
                $data['state_id'] = (string) $city->state_id;
                $data['country_id'] = (string) $city->country_id;
                if ($city->relationLoaded('country') && $city->country) {
                    $data['country'] = $city->country->name;
                }
            }
        } elseif (! empty($data['country_id'] ?? null)) {
            $c = Country::query()->find((int) $data['country_id']);
            if ($c) {
                $data['country'] = $c->name;
            }
        }

        foreach ($data as $key => $value) {
            Setting::setValue($key, $value === null ? '' : (string) $value);
        }

        return response()->json(['message' => 'Paramètres enregistrés.']);
    }

    /**
     * Identité visuelle lisible par tous (sans permission manage_settings) : sidebar, favicon, pages publiques.
     */
    public function branding(): JsonResponse
    {
        $keys = [
            'logo_path', 'favicon_path', 'site_name', 'hub_brand_name', 'show_sidebar_brand_with_logo',
            'currency', 'currency_symbol', 'symbol_position',
        ];
        $fromDb = Setting::query()->whereIn('key', $keys)->pluck('value', 'key')->all();

        $logoPath = $fromDb['logo_path'] ?? null;
        $faviconPath = $fromDb['favicon_path'] ?? null;
        $showBrand = $fromDb['show_sidebar_brand_with_logo'] ?? '';
        if ($showBrand === '') {
            $showBrand = '1';
        }

        $currency = trim((string) ($fromDb['currency'] ?? '')) !== '' ? trim((string) $fromDb['currency']) : 'EUR';
        $symbolRaw = trim((string) ($fromDb['currency_symbol'] ?? ''));
        $symbolPosition = ($fromDb['symbol_position'] ?? '') === 'suffix' ? 'after' : 'before';

        return response()->json([
            'logo_url' => $logoPath ? ShipmentDocumentSettings::publicStorageWebPath($logoPath) : null,
            'favicon_url' => $faviconPath ? ShipmentDocumentSettings::publicStorageWebPath($faviconPath) : null,
            'site_name' => (string) ($fromDb['site_name'] ?? ''),
            'hub_brand_name' => (string) ($fromDb['hub_brand_name'] ?? ''),
            'show_sidebar_brand_with_logo' => $showBrand,
            'currency' => $currency,
            'currency_symbol' => $symbolRaw,
            'currency_position' => $symbolPosition,
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
        ]);

        $path = $request->file('logo')->store('branding', 'public');
        Setting::setValue('logo_path', $path);

        return response()->json([
            'message' => 'Logo mis à jour.',
            'logo_url' => ShipmentDocumentSettings::publicStorageWebPath($path),
        ]);
    }

    public function uploadFavicon(Request $request): JsonResponse
    {
        $request->validate([
            'favicon' => ['required', 'image', 'mimes:png,ico', 'max:512'],
        ]);

        $path = $request->file('favicon')->store('branding', 'public');
        Setting::setValue('favicon_path', $path);

        return response()->json([
            'message' => 'Favicon mis à jour.',
            'favicon_url' => ShipmentDocumentSettings::publicStorageWebPath($path),
        ]);
    }
}
