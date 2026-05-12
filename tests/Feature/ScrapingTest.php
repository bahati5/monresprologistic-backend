<?php

namespace Tests\Feature;

use App\Services\Scraping\MerchantParserFactory;
use App\Services\Scraping\Parsers\AliExpressParser;
use App\Services\Scraping\Parsers\AmazonParser;
use App\Services\Scraping\Parsers\EbayParser;
use App\Services\Scraping\Parsers\GenericOpenGraphParser;
use App\Services\Scraping\Parsers\ZaraParser;
use Tests\TestCase;

class ScrapingTest extends TestCase
{
    /**
     * SCR-AA-001: MerchantParserFactory returns AmazonParser for amazon.com URLs.
     */
    public function test_scr_aa_001_factory_returns_amazon_parser(): void
    {
        $parser = MerchantParserFactory::for('https://www.amazon.com/dp/B08N5WRWNW');

        $this->assertInstanceOf(AmazonParser::class, $parser);
    }

    /**
     * SCR-AA-002: MerchantParserFactory returns AliExpressParser for aliexpress.com URLs.
     */
    public function test_scr_aa_002_factory_returns_aliexpress_parser(): void
    {
        $parser = MerchantParserFactory::for('https://www.aliexpress.com/item/1005007123456789.html');

        $this->assertInstanceOf(AliExpressParser::class, $parser);
    }

    /**
     * SCR-AA-003: MerchantParserFactory returns ZaraParser for zara.com URLs.
     */
    public function test_scr_aa_003_factory_returns_zara_parser(): void
    {
        $parser = MerchantParserFactory::for('https://www.zara.com/us/en/some-product-p12345678.html');

        $this->assertInstanceOf(ZaraParser::class, $parser);
    }

    /**
     * SCR-AA-004: MerchantParserFactory returns EbayParser for ebay.com URLs.
     */
    public function test_scr_aa_004_factory_returns_ebay_parser(): void
    {
        $parser = MerchantParserFactory::for('https://www.ebay.com/itm/123456789012');

        $this->assertInstanceOf(EbayParser::class, $parser);
    }

    /**
     * SCR-AA-005: MerchantParserFactory returns GenericOpenGraphParser for unknown domains.
     */
    public function test_scr_aa_005_factory_returns_generic_open_graph_parser_for_unknown_domains(): void
    {
        $parser = MerchantParserFactory::for('https://boutique-unknown.example.net/product/Sku-001');

        $this->assertInstanceOf(GenericOpenGraphParser::class, $parser);
    }

    /**
     * SCR-AA-006: AmazonParser extracts name, price, and currency from Open Graph / product meta tags.
     *
     * Attribute order matches AmazonParser::extractMeta (property/name before content).
     */
    public function test_scr_aa_006_amazon_parser_parses_og_title_and_product_price_meta_tags(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta property="og:title" content="Wireless Bluetooth Headphones &amp; Case" />
<meta property="product:price:amount" content="49,99" />
<meta property="product:price:currency" content="EUR" />
</head>
<body></body>
</html>
HTML;

        $parser = new AmazonParser;
        $url = 'https://www.amazon.fr/dp/B0TEST123';
        $data = $parser->parse($html, $url);

        $this->assertTrue($data->success);
        $this->assertSame('Wireless Bluetooth Headphones & Case', $data->name);
        $this->assertSame(49.99, $data->price);
        $this->assertSame('EUR', $data->currency);
        $this->assertSame('Amazon', $data->merchant);
    }
}
