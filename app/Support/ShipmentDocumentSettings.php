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

    /**
     * URL publique du fichier (disque public), en général absolue via APP_URL.
     * Évite les images cassées quand le SPA est sur un autre port / domaine que l’API.
     */
    public static function publicStorageUrl(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Chemin relatif /storage/... pour le SPA : résolu côté front (proxy Vite ou VITE_API_URL).
     * Évite les URLs absolues basées sur APP_URL incorrect (localhost vs LAN, etc.).
     */
    public static function publicStorageWebPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return '/storage/'.$path;
    }

    /**
     * Logo en data URI pour factures / PDF.
     *
     * DomPDF intègre le JPEG et le SVG sans extension GD ; le PNG exige GD (sinon erreur 500).
     * Avec GD : on renvoie le fichier tel quel. Sans GD : conversion PNG/WebP/GIF → JPEG via Imagick si dispo.
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

        $raw = @file_get_contents($full);
        if ($raw === false || $raw === '') {
            return null;
        }

        $mime = strtolower((string) (@mime_content_type($full) ?: ''));
        $lowerPath = strtolower($relativePath);

        $isSvg = str_contains($mime, 'svg') || str_ends_with($lowerPath, '.svg');
        if ($isSvg) {
            $m = str_contains($mime, 'svg') ? (str_contains($mime, 'xml') ? $mime : 'image/svg+xml') : 'image/svg+xml';

            return 'data:'.$m.';base64,'.base64_encode($raw);
        }

        $isJpeg = in_array($mime, ['image/jpeg', 'image/jpg'], true)
            || str_ends_with($lowerPath, '.jpg')
            || str_ends_with($lowerPath, '.jpeg');

        if ($isJpeg) {
            return 'data:image/jpeg;base64,'.base64_encode($raw);
        }

        if (extension_loaded('gd')) {
            if ($mime === '' || ! str_starts_with($mime, 'image/')) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode($raw);
        }

        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick;
                $im->readImageBlob($raw);
                if (strtoupper((string) $im->getImageFormat()) === 'SVG' || strtoupper((string) $im->getImageFormat()) === 'MSVG') {
                    $im->setImageFormat('svg');
                    $blob = $im->getImageBlob();
                    $im->clear();
                    $im->destroy();

                    return 'data:image/svg+xml;base64,'.base64_encode($blob);
                }
                $im->setImageBackgroundColor(new \ImagickPixel('white'));
                if ($im->getImageAlphaChannel()) {
                    $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                    $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                }
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(90);
                $jpeg = $im->getImageBlob();
                $im->clear();
                $im->destroy();

                return 'data:image/jpeg;base64,'.base64_encode($jpeg);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
