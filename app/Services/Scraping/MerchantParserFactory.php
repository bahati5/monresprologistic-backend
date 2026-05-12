<?php

namespace App\Services\Scraping;

use App\Services\Scraping\Parsers\AliExpressParser;
use App\Services\Scraping\Parsers\AmazonParser;
use App\Services\Scraping\Parsers\AsosParser;
use App\Services\Scraping\Parsers\EbayParser;
use App\Services\Scraping\Parsers\GenericOpenGraphParser;
use App\Services\Scraping\Parsers\HMParser;
use App\Services\Scraping\Parsers\MerchantParser;
use App\Services\Scraping\Parsers\Parser1688;
use App\Services\Scraping\Parsers\SheinParser;
use App\Services\Scraping\Parsers\TaobaoParser;
use App\Services\Scraping\Parsers\ZaraParser;

class MerchantParserFactory
{
    /** @var list<class-string<MerchantParser>> */
    private static array $parsers = [
        AmazonParser::class,
        AliExpressParser::class,
        Parser1688::class,
        TaobaoParser::class,
        ZaraParser::class,
        SheinParser::class,
        AsosParser::class,
        HMParser::class,
        EbayParser::class,
    ];

    private const CHINESE_DOMAINS = ['1688.com', 'taobao.com', 'tmall.com'];

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

    public static function isChineseSite(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host);

        foreach (self::CHINESE_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
