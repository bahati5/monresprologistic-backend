<?php

namespace App\Services\Scraping\Parsers;

use App\Services\Scraping\ProductData;

class AsosParser implements MerchantParser
{
    public function parse(string $html, string $url): ProductData
    {
        $name = $this->extractMeta($html, 'og:title')
            ?? $this->extractJsonLdField($html, 'name')
            ?? $this->extractTitle($html);

        $priceRaw = $this->extractMeta($html, 'product:price:amount')
            ?? $this->extractJsonLdField($html, 'price')
            ?? $this->extractPriceFromPageData($html);

        $currency = $this->extractMeta($html, 'product:price:currency')
            ?? $this->extractJsonLdField($html, 'priceCurrency')
            ?? $this->guessCurrencyFromUrl($url);

        $image = $this->extractMeta($html, 'og:image')
            ?? $this->extractMeta($html, 'twitter:image');

        $price = $priceRaw ? $this->parsePrice($priceRaw) : null;

        return new ProductData(
            name: $name ? $this->cleanName($name) : null,
            price: $price,
            currency: $currency ? strtoupper(trim($currency)) : 'GBP',
            imageUrl: $image,
            description: $this->extractMeta($html, 'og:description'),
            merchant: 'ASOS',
            success: $name !== null && $name !== '',
        );
    }

    public static function supportsDomain(string $domain): bool
    {
        return str_ends_with($domain, 'asos.com');
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/\s*[-|]\s*ASOS.*$/i', '', $name);

        return trim($name);
    }

    private function guessCurrencyFromUrl(string $url): string
    {
        if (preg_match('/asos\.com\/([a-z]{2})\//i', $url, $m)) {
            return match (strtolower($m[1])) {
                'fr', 'de', 'es', 'it', 'nl' => 'EUR',
                'us', 'au' => 'USD',
                default => 'GBP',
            };
        }

        return 'GBP';
    }

    private function extractPriceFromPageData(string $html): ?string
    {
        if (preg_match('/"current"\s*:\s*\{[^}]*"value"\s*:\s*([0-9.]+)/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/"productPrice"\s*:\s*\{[^}]*"current"\s*:\s*([0-9.]+)/i', $html, $m)) {
            return $m[1];
        }

        return null;
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
}
