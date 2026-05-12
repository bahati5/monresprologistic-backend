<?php

namespace App\Services\Scraping\Parsers;

use App\Services\Scraping\ProductData;

class AliExpressParser implements MerchantParser
{
    public function parse(string $html, string $url): ProductData
    {
        $name = $this->extractLocalizedTitle($html, $url)
            ?? $this->extractMeta($html, 'og:title')
            ?? $this->extractMeta($html, 'twitter:title')
            ?? $this->extractJsonLdField($html, 'name')
            ?? $this->extractTitle($html);

        $priceRaw = $this->extractMeta($html, 'product:price:amount')
            ?? $this->extractJsonLdField($html, 'price')
            ?? $this->extractPriceFromPageData($html);

        $currency = $this->extractMeta($html, 'product:price:currency')
            ?? $this->extractJsonLdField($html, 'priceCurrency')
            ?? $this->extractCurrencyFromPageData($html)
            ?? 'USD';

        $image = $this->extractMeta($html, 'og:image')
            ?? $this->extractMeta($html, 'twitter:image');

        $price = $priceRaw ? $this->parsePrice($priceRaw) : null;

        return new ProductData(
            name: $name ? $this->cleanProductName($name) : null,
            price: $price,
            currency: $currency ? strtoupper(trim($currency)) : 'USD',
            imageUrl: $image,
            description: $this->extractMeta($html, 'og:description'),
            merchant: 'AliExpress',
            success: $name !== null && $name !== '',
        );
    }

    public static function supportsDomain(string $domain): bool
    {
        return (bool) preg_match('/aliexpress\.(com|us|ru)$/i', $domain)
            || str_ends_with($domain, '.aliexpress.com');
    }

    private function cleanProductName(string $name): string
    {
        $name = preg_replace('/\s*[-|]\s*AliExpress.*$/i', '', $name);
        $name = preg_replace('/\s*\|\s*\d+(\.\d+)?%\s*Off.*$/i', '', $name);

        return trim($name);
    }

    private function parsePrice(string $raw): ?float
    {
        $clean = preg_replace('/[^0-9,\.\-]/', '', $raw);
        if ($clean === '' || $clean === null) {
            return null;
        }
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace(',', '', $clean);
        } else {
            $clean = str_replace(',', '.', $clean);
        }
        $value = (float) $clean;

        return ($value > 0 && is_finite($value)) ? round($value, 2) : null;
    }

    private function extractMeta(string $html, string $property): ?string
    {
        if (preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
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

    private function extractJsonLdField(string $html, string $field): ?string
    {
        if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $block) {
            $decoded = json_decode(trim($block), true);
            if (!is_array($decoded)) {
                continue;
            }
            if (isset($decoded[$field])) {
                return (string) $decoded[$field];
            }
            $offers = $decoded['offers'] ?? null;
            if (is_array($offers)) {
                $offer = isset($offers[0]) ? $offers[0] : $offers;
                if (isset($offer[$field])) {
                    return (string) $offer[$field];
                }
            }
        }

        return null;
    }

    /**
     * AliExpress embeds product data in window.__PRODUCT_DETAIL_V2 or similar JSON blobs.
     * Try to extract the localized title from these.
     */
    private function extractLocalizedTitle(string $html, string $url): ?string
    {
        $patterns = [
            '/window\.__PRODUCT_DETAIL(?:_V2)?_DATA__\s*=\s*(\{.+?\});\s*<\/script>/s',
            '/"subject"\s*:\s*"([^"]{10,})"/i',
            '/"title"\s*:\s*"([^"]{10,})"/i',
        ];

        if (preg_match($patterns[0], $html, $m)) {
            $data = @json_decode($m[1], true);
            if (is_array($data)) {
                $title = $data['name'] ?? $data['subject'] ?? $data['title'] ?? null;
                if ($title && is_string($title) && strlen($title) > 5) {
                    return $title;
                }
                $pageModule = $data['pageModule'] ?? $data['data'] ?? [];
                if (is_array($pageModule)) {
                    $title = $pageModule['subject'] ?? $pageModule['title'] ?? $pageModule['name'] ?? null;
                    if ($title && is_string($title) && strlen($title) > 5) {
                        return $title;
                    }
                }
            }
        }

        if (preg_match('/data-title="([^"]{10,})"/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('/<h1[^>]*class="[^"]*product-title[^"]*"[^>]*>([^<]+)</i', $html, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractPriceFromPageData(string $html): ?string
    {
        if (preg_match('/"formatedAmount"\s*:\s*"([^"]+)"/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/"minPrice"\s*:\s*"?([0-9.,]+)"?/i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractCurrencyFromPageData(string $html): ?string
    {
        if (preg_match('/"currencyCode"\s*:\s*"([A-Z]{3})"/i', $html, $m)) {
            return $m[1];
        }

        return null;
    }
}
