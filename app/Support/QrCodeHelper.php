<?php

declare(strict_types=1);

namespace App\Support;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

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
            $qr = QrCode::create($tracking)->setSize($size)->setMargin(4);
            // DomPDF exige GD pour intégrer les PNG ; le SVG fonctionne sans GD.
            $writer = extension_loaded('gd') ? new PngWriter() : new SvgWriter();

            return $writer->write($qr)->getDataUri();
        } catch (\Throwable) {
            return null;
        }
    }
}
