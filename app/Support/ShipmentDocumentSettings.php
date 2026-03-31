<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

class ShipmentDocumentSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function merged(): array
    {
        $cfg = config('shipment_documents', []);
        $logoPath = Setting::getValue('logo_path');
        $phone = Setting::getValue('phone_mobile') ?: Setting::getValue('phone_fixed');
        $logoDataUri = self::logoFileDataUri($logoPath);
        $logoUrl = $logoPath ? self::publicStorageUrl($logoPath) : null;
        if ($logoUrl !== null && $logoDataUri === null && ! extension_loaded('gd') && $logoPath && Storage::disk('public')->exists($logoPath)) {
            $mime = @mime_content_type(Storage::disk('public')->path($logoPath)) ?: '';
            if (preg_match('#^image/(png|jpe?g|webp|gif)$#i', (string) $mime)) {
                $logoUrl = null;
            }
        }

        return array_merge($cfg, [
            'site_name' => Setting::getValue('site_name', $cfg['defaults']['site_name'] ?? 'Monrespro'),
            'site_email' => Setting::getValue('site_email', ''),
            'nit' => Setting::getValue('nit', ''),
            'phone' => $phone ?? '',
            'address' => Setting::getValue('address', ''),
            'country' => Setting::getValue('country', ''),
            'city' => Setting::getValue('city', ''),
            'zip_code' => Setting::getValue('zip_code', ''),
            'currency' => Setting::getValue('currency', 'EUR'),
            'currency_symbol' => Setting::getValue('currency_symbol', '€'),
            'logo_url' => $logoUrl,
            'logo_data_uri' => $logoDataUri,
            'logo_thumb_w' => $cfg['logo_thumb']['w'] ?? 120,
            'logo_thumb_h' => $cfg['logo_thumb']['h'] ?? 40,
            'weight_unit' => $cfg['weight_unit'] ?? 'kg',
            'volumetric_divisor' => (float) ($cfg['volumetric_divisor'] ?? 5000),
            'barcode_base_url' => $cfg['barcode_base_url'] ?? 'https://barcode.tec-it.com/barcode.ashx',
            'transport_company' => Setting::getValue('default_transport_company', ''),
            'shipping_mode_label' => Setting::getValue('default_shipping_mode', ''),
            'invoice_terms' => trim((string) Setting::getValue('invoice_terms', ''))
                ?: (string) ($cfg['default_invoice_terms'] ?? ''),
            'signing_company' => Setting::getValue('signing_company', ''),
            'signing_customer' => Setting::getValue('signing_customer', ''),
        ]);
    }

    public static function tecItUrl(string $data, string $code, array $overrides = []): string
    {
        $doc = self::merged();
        $base = rtrim((string) $doc['barcode_base_url'], '?&');

        $query = array_merge([
            'data' => $data,
            'code' => $code,
            'multiplebarcodes' => 'false',
            'translate-esc' => $code === 'QRCode' ? 'false' : 'true',
            'unit' => 'Fit',
            'dpi' => '96',
            'imagetype' => 'Gif',
            'rotation' => '0',
            'color' => '%23000000',
            'bgcolor' => '%23ffffff',
            'qunit' => 'Mm',
            'quiet' => '0',
        ], $overrides);

        if ($code === 'QRCode') {
            $query['eclevel'] = $query['eclevel'] ?? 'L';
        }

        return $base.'?'.http_build_query($query);
    }

    /** URL relative /storage/... pour le même hôte que le front (proxy Vite). */
    public static function publicStorageUrl(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return '/storage/'.$path;
    }

    /**
     * Image logo en data URI pour DomPDF (évite les échecs de chargement HTTP distants).
     */
    public static function logoFileDataUri(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }
        if (! Storage::disk('public')->exists($relativePath)) {
            return null;
        }
        $full = Storage::disk('public')->path($relativePath);
        if (! is_readable($full)) {
            return null;
        }
        $mime = @mime_content_type($full) ?: 'image/png';
        if (! str_starts_with((string) $mime, 'image/')) {
            return null;
        }

        // DomPDF intègre les images raster via GD sur beaucoup d’environnements ; sans GD, pas de logo embarqué.
        if (! extension_loaded('gd') && preg_match('#^image/(png|jpe?g|webp|gif)$#i', (string) $mime)) {
            return null;
        }

        $raw = @file_get_contents($full);
        if ($raw === false) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($raw);
    }
}
