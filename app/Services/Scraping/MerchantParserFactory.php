<?php

namespace App\Services\Scraping;

use App\Services\Scraping\Parsers\AmazonParser;
use App\Services\Scraping\Parsers\EcommerceMetaParser;
use App\Services\Scraping\Parsers\GenericOpenGraphParser;
use App\Services\Scraping\Parsers\MerchantParser;

class MerchantParserFactory
{
    /** @var list<class-string<MerchantParser>> */
    private static array $parsers = [
        AmazonParser::class,
        EcommerceMetaParser::class,
    ];

    public static function for(string $url): MerchantParser
    {
        $domain = parse_url($url, PHP_URL_HOST) ?? '';
        $domain = preg_replace('/^www\./', '', $domain);

        foreach (self::$parsers as $parserClass) {
            if ($parserClass::supportsDomain($domain)) {
                return new $parserClass;
            }
        }

        return new GenericOpenGraphParser;
    }
}
