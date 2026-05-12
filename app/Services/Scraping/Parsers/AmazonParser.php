<?php

namespace App\Services\Scraping\Parsers;

use App\Services\Scraping\ProductData;

class AmazonParser implements MerchantParser
{
    public function parse(string $html, string $url): ProductData
    {
        $name = $this->extractMeta($html, 'og:title')
            ?? $this->extractById($html, 'productTitle')
            ?? $this->extractByClass($html, 'qa-title-text');

        $priceRaw = $this->extractMeta($html, 'product:price:amount')
            ?? $this->extractByClass($html, 'a-price-whole')
            ?? $this->extractByClass($html, 'priceToPay');

        $currency = $this->extractMeta($html, 'product:price:currency') 
            ?? $this->extractByClass($html, 'a-price-symbol')
            ?? $this->detectCurrency($url);
        
        $image = $this->extractMeta($html, 'og:image')
            ?? $this->extractById($html, 'landingImage');

        $price = $priceRaw ? (float) str_replace([',', ' '], ['.', ''], $priceRaw) : null;

        // Amazon retire souvent les meta prix côté serveur (anti-bot) : exiger le prix
        // faisait échouer presque toutes les extractions alors que titre + image suffisent
        // pour préremplir le formulaire (prix saisi manuellement).
        $nameOk = $name !== null && trim($name) !== '';

        return new ProductData(
            name: $name ? trim($name) : null,
            price: $price,
            currency: $currency,
            imageUrl: $image,
            merchant: 'Amazon',
            success: $nameOk,
        );
    }

    public static function supportsDomain(string $domain): bool
    {
        return (bool) preg_match('/amazon\.(com|fr|de|co\.uk|es|it|ca)$/i', $domain);
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

    private function extractByClass(string $html, string $class): ?string
    {
        if (preg_match('/<[^>]+class=["\'][^"\']*\b' . preg_quote($class, '/') . '\b[^"\']*["\'][^>]*>([^<]+)/i', $html, $m)) {
            return trim(strip_tags($m[1]));
        }

        return null;
    }

    private function detectCurrency(string $url): string
    {
        if (str_contains($url, 'amazon.fr') || str_contains($url, 'amazon.de') || str_contains($url, 'amazon.es') || str_contains($url, 'amazon.it')) {
            return 'EUR';
        }
        if (str_contains($url, 'amazon.co.uk')) {
            return 'GBP';
        }

        return 'USD';
    }
}
