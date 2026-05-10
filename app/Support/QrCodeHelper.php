<?php

declare(strict_types=1);

namespace App\Support;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Picqer\Barcode\BarcodeGeneratorPng;

final class QrCodeHelper
{
    /**
     * PNG en data URI (DomPDF, navigateur).
     */
    public static function trackingDataUri(string $tracking, int $size = 180): ?string
    {
        $tracking = trim($tracking);
        if ($tracking === '') {
            return null;
        }

        try {
            $trackingUrl = rtrim((string) config('app.url'), '/') . '/track/' . urlencode($tracking);
            $qr = QrCode::create($trackingUrl)->setSize($size)->setMargin(4);
            // DomPDF exige GD pour intégrer les PNG ; le SVG fonctionne sans GD.
            $writer = extension_loaded('gd') ? new PngWriter : new SvgWriter;

            return $writer->write($qr)->getDataUri();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Barcode 1D (Code 128) en data URI.
     */
    public static function barcodeDataUri(string $tracking): ?string
    {
        $tracking = trim($tracking);
        if ($tracking === '') {
            return null;
        }

        if (! extension_loaded('gd')) {
            return null;
        }

        try {
            $generator = new BarcodeGeneratorPng;
            $barcode = $generator->getBarcode($tracking, $generator::TYPE_CODE_128);

            return 'data:image/png;base64,'.base64_encode($barcode);
        } catch (\Throwable) {
            return null;
        }
    }
}
