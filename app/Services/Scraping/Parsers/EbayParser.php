<?php

namespace App\Services\Scraping\Parsers;

use App\Services\Scraping\ProductData;

class EbayParser implements MerchantParser
{
    public function parse(string $html, string $url): ProductData
    {
        $name = $this->extractMeta($html, 'og:title')
            ?? $this->extractById($html, 'itemTitle')
            ?? $this->extractTitle($html);

        $priceRaw = $this->extractMeta($html, 'product:price:amount')
            ?? $this->extractById($html, 'prcIsum')
            ?? $this->extractByItemprop($html, 'price');

        $currency = $this->extractMeta($html, 'product:price:currency')
            ?? $this->extractByItemprop($html, 'priceCurrency')
            ?? $this->guessCurrencyFromUrl($url);

        $image = $this->extractMeta($html, 'og:image');

        $price = $priceRaw ? $this->parsePrice($priceRaw) : null;

        return new ProductData(
            name: $name ? $this->cleanName($name) : null,
            price: $price,
            currency: $currency ? strtoupper(trim($currency)) : null,
            imageUrl: $image,
            description: $this->extractMeta($html, 'og:description'),
            merchant: 'eBay',
            success: $name !== null && $name !== '',
        );
    }

    public static function supportsDomain(string $domain): bool
    {
        return (bool) preg_match('/ebay\.(com|fr|de|co\.uk|es|it|ca|com\.au)$/i', $domain);
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/\s*[-|]\s*eBay.*$/i', '', $name);

        return trim($name);
    }

    private function guessCurrencyFromUrl(string $url): string
    {
        if (str_contains($url, 'ebay.fr') || str_contains($url, 'ebay.de') || str_contains($url, 'ebay.es') || str_contains($url, 'ebay.it')) {
            return 'EUR';
        }
        if (str_contains($url, 'ebay.co.uk')) {
            return 'GBP';
        }

        return 'USD';
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

    private function extractById(string $html, string $id): ?string
    {
        if (preg_match('/<[^>]+id=["\']' . preg_quote($id, '/') . '["\'][^>]*>([^<]+)/i', $html, $m)) {
            return trim(strip_tags($m[1]));
        }

        return null;
    }

    private function extractByItemprop(string $html, string $prop): ?string
    {
        if (preg_match('/<[^>]+itemprop=["\']' . preg_quote($prop, '/') . '["\'][^>]*content=["\']([^"\']+)/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/<[^>]+itemprop=["\']' . preg_quote($prop, '/') . '["\'][^>]*>([^<]+)/i', $html, $m)) {
            return trim($m[1]);
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
}
