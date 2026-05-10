<?php

namespace App\Services\Scraping\Parsers;

use App\Services\Scraping\ProductData;

class EcommerceMetaParser implements MerchantParser
{
    /** @var array<string, string> */
    private const DOMAIN_MERCHANT_MAP = [
        'aliexpress.com' => 'AliExpress',
        'zara.com' => 'Zara',
        'asos.com' => 'ASOS',
        'shein.com' => 'Shein',
        'hm.com' => 'H&M',
        'ebay.com' => 'eBay',
        'ebay.fr' => 'eBay',
        'ebay.co.uk' => 'eBay',
    ];

    public function parse(string $html, string $url): ProductData
    {
        $name = $this->extractMeta($html, 'og:title')
            ?? $this->extractMeta($html, 'twitter:title')
            ?? $this->extractTitle($html);

        $priceRaw = $this->extractMeta($html, 'product:price:amount')
            ?? $this->extractMeta($html, 'og:price:amount')
            ?? $this->extractMeta($html, 'twitter:data1')
            ?? $this->extractJsonLdPrice($html);

        $currency = $this->extractMeta($html, 'product:price:currency')
            ?? $this->extractMeta($html, 'og:price:currency')
            ?? $this->extractJsonLdCurrency($html);

        $image = $this->extractMeta($html, 'og:image')
            ?? $this->extractMeta($html, 'twitter:image');

        $price = $priceRaw ? $this->parsePrice($priceRaw) : null;

        return new ProductData(
            name: $name ? trim($name) : null,
            price: $price,
            currency: $currency ? strtoupper(trim($currency)) : null,
            imageUrl: $image ? trim($image) : null,
            description: $this->extractMeta($html, 'og:description'),
            merchant: $this->merchantFromUrl($url),
            success: $name !== null && $name !== '',
        );
    }

    public static function supportsDomain(string $domain): bool
    {
        $host = strtolower(trim($domain));
        foreach (array_keys(self::DOMAIN_MERCHANT_MAP) as $supported) {
            if ($host === $supported || str_ends_with($host, '.'.$supported)) {
                return true;
            }
        }

        return false;
    }

    private function merchantFromUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        if (! is_string($host) || $host === '') {
            return 'E-commerce';
        }
        foreach (self::DOMAIN_MERCHANT_MAP as $domain => $label) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $label;
            }
        }

        return $host;
    }

    private function extractMeta(string $html, string $property): ?string
    {
        if (preg_match('/<meta[^>]+(?:property|name)=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        return null;
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>([^<]+)/i', $html, $m)) {
            return trim(strip_tags($m[1]));
        }

        return null;
    }

    private function parsePrice(string $raw): ?float
    {
        $clean = trim($raw);
        if ($clean === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9,\.\-]/', '', $clean) ?? '';
        if ($clean === '') {
            return null;
        }
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace(',', '', $clean);
        } else {
            $clean = str_replace(',', '.', $clean);
        }
        $value = (float) $clean;
        if (! is_finite($value) || $value <= 0) {
            return null;
        }

        return round($value, 2);
    }

    private function extractJsonLdPrice(string $html): ?string
    {
        if (! preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $block) {
            $decoded = json_decode(trim((string) $block), true);
            if (! is_array($decoded)) {
                continue;
            }
            $offers = $decoded['offers'] ?? null;
            if (is_array($offers) && isset($offers['price'])) {
                return (string) $offers['price'];
            }
            if (is_array($offers) && isset($offers[0]['price'])) {
                return (string) $offers[0]['price'];
            }
        }

        return null;
    }

    private function extractJsonLdCurrency(string $html): ?string
    {
        if (! preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $block) {
            $decoded = json_decode(trim((string) $block), true);
            if (! is_array($decoded)) {
                continue;
            }
            $offers = $decoded['offers'] ?? null;
            if (is_array($offers) && isset($offers['priceCurrency'])) {
                return (string) $offers['priceCurrency'];
            }
            if (is_array($offers) && isset($offers[0]['priceCurrency'])) {
                return (string) $offers[0]['priceCurrency'];
            }
        }

        return null;
    }
}
