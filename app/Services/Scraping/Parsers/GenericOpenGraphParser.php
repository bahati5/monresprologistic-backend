<?php

namespace App\Services\Scraping\Parsers;

use App\Services\Scraping\ProductData;

class GenericOpenGraphParser implements MerchantParser
{
    public function parse(string $html, string $url): ProductData
    {
        $name = $this->extractMeta($html, 'og:title')
            ?? $this->extractMeta($html, 'twitter:title')
            ?? $this->extractTitle($html);

        $priceRaw = $this->extractMeta($html, 'product:price:amount')
            ?? $this->extractMeta($html, 'og:price:amount');

        $currency = $this->extractMeta($html, 'product:price:currency')
            ?? $this->extractMeta($html, 'og:price:currency');

        $image = $this->extractMeta($html, 'og:image');
        $description = $this->extractMeta($html, 'og:description');

        $price = $priceRaw ? (float) str_replace([',', ' '], ['.', ''], $priceRaw) : null;
        $domain = parse_url($url, PHP_URL_HOST) ?? '';
        $merchant = preg_replace('/^www\./', '', $domain);

        return new ProductData(
            name: $name ? trim($name) : null,
            price: $price,
            currency: $currency,
            imageUrl: $image,
            description: $description ? trim($description) : null,
            merchant: $merchant,
            success: $name !== null,
        );
    }

    public static function supportsDomain(string $domain): bool
    {
        return true;
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
}
