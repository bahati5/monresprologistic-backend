<?php

namespace App\Services\Scraping\Parsers;

use App\Services\Scraping\ProductData;

interface MerchantParser
{
    public function parse(string $html, string $url): ProductData;

    public static function supportsDomain(string $domain): bool;
}
