<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NetworkPrintService
{
    /**
     * Print a PDF document to a configured network printer.
     * Uses IPP (Internet Printing Protocol) for direct printing.
     */
    public static function print(string $pdfContent, ?int $agencyId = null): array
    {
        $printerUrl = self::printerUrl($agencyId);

        if (! $printerUrl) {
            return [
                'success' => false,
                'error' => 'Aucune imprimante configurée pour cette agence.',
                'fallback' => true,
            ];
        }

        try {
            $ippHeader = self::buildIppPrintJobRequest($pdfContent);

            $response = Http::withBody($ippHeader, 'application/ipp')
                ->timeout(15)
                ->post($printerUrl);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Document envoyé à l\'imprimante.',
                ];
            }

            return [
                'success' => false,
                'error' => "HTTP {$response->status()}",
                'fallback' => true,
            ];
        } catch (\Throwable $e) {
            Log::warning("Network print failed: {$e->getMessage()}");

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'fallback' => true,
            ];
        }
    }

    public static function printerUrl(?int $agencyId = null): ?string
    {
        if ($agencyId) {
            $url = Setting::getValue("printer_url_agency_{$agencyId}");
            if ($url) {
                return (string) $url;
            }
        }

        return Setting::getValue('printer_url') ? (string) Setting::getValue('printer_url') : null;
    }

    /**
     * Build a minimal IPP Print-Job request body.
     */
    private static function buildIppPrintJobRequest(string $pdfContent): string
    {
        $header = pack('CCn', 1, 1, 0x0002); // IPP 1.1, Print-Job
        $header .= pack('N', 1); // request-id

        // Operation attributes group
        $header .= pack('C', 0x01); // operation-attributes-tag
        $header .= self::ippAttribute(0x47, 'attributes-charset', 'utf-8');
        $header .= self::ippAttribute(0x48, 'attributes-natural-language', 'fr-fr');
        $header .= self::ippAttribute(0x49, 'document-format', 'application/pdf');

        $header .= pack('C', 0x03); // end-of-attributes-tag
        $header .= $pdfContent;

        return $header;
    }

    private static function ippAttribute(int $tag, string $name, string $value): string
    {
        return pack('CnA*nA*', $tag, strlen($name), $name, strlen($value), $value);
    }
}
