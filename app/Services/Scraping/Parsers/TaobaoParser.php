<?php

namespace App\Services\Scraping\Parsers;

use App\Services\Scraping\ProductData;

class TaobaoParser implements MerchantParser
{
    public function parse(string $html, string $url): ProductData
    {
        $name = $this->extractFromPageData($html, 'title')
            ?? $this->extractMeta($html, 'og:title')
            ?? $this->extractTitle($html);

        $priceRaw = $this->extractPriceFromPageData($html)
            ?? $this->extractMeta($html, 'product:price:amount');

        $image = $this->extractMeta($html, 'og:image')
            ?? $this->extractImageFromPageData($html);

        $price = $priceRaw ? $this->parsePrice($priceRaw) : null;

        return new ProductData(
            name: $name ? $this->cleanName($name) : null,
            price: $price,
            currency: 'CNY',
            imageUrl: $image,
            description: $this->extractMeta($html, 'og:description'),
            merchant: 'Taobao',
            success: $name !== null && $name !== '',
        );
    }

    public static function supportsDomain(string $domain): bool
    {
        return str_ends_with($domain, 'taobao.com')
            || str_ends_with($domain, 'tmall.com');
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/\s*[-|_]\s*淘宝网.*$/u', '', $name);
        $name = preg_replace('/\s*[-|_]\s*天猫.*$/u', '', $name);
        $name = preg_replace('/\s*[-|_]\s*Taobao.*$/iu', '', $name);

        return trim($name);
    }

    private function extractFromPageData(string $html, string $field): ?string
    {
        $patterns = [
            '/g_page_config\s*=\s*(\{.+?\});\s*$/ms',
            '/var\s+_DATA_Aria\s*=\s*(\{.+?\});\s*<\/script>/s',
            '/TShop\.Setup\s*\(\s*(\{.+?\})\s*\)/s',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $data = @json_decode($m[1], true);
                if (is_array($data)) {
                    $value = $this->deepFind($data, $field);
                    if ($value && is_string($value) && mb_strlen($value) > 3) {
                        return $value;
                    }
                }
            }
        }

        if (preg_match('/<h3[^>]*class="[^"]*tb-main-title[^"]*"[^>]*[^>]*data-title="([^"]+)"/iu', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('/"title"\s*:\s*"([^"]{5,})"/u', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractPriceFromPageData(string $html): ?string
    {
        $patterns = [
            '/"price"\s*:\s*"?([0-9]+\.?[0-9]*)"?/',
            '/id="J_StrPrice"[^>]*>.*?<em[^>]*>([0-9]+\.?[0-9]*)/s',
            '/"reservePrice"\s*:\s*"?([0-9]+\.?[0-9]*)"?/',
            '/class="[^"]*tb-rmb-num[^"]*"[^>]*>([0-9]+\.?[0-9]*)/i',
            '/"promotionPrice"\s*:\s*"?([0-9]+\.?[0-9]*)"?/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function extractImageFromPageData(string $html): ?string
    {
        if (preg_match('/id="J_ImgBooth"[^>]+src="((?:https?:)?\/\/[^"]+)"/i', $html, $m)) {
            $src = $m[1];

            return str_starts_with($src, '//') ? 'https:' . $src : $src;
        }
        if (preg_match('/"pic"\s*:\s*"((?:https?:)?\/\/[^"]+)"/i', $html, $m)) {
            $src = $m[1];

            return str_starts_with($src, '//') ? 'https:' . $src : $src;
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

    /**
     * @param array<string, mixed> $data
     */
    private function deepFind(array $data, string $key, int $depth = 0): mixed
    {
        if ($depth > 5) {
            return null;
        }
        if (isset($data[$key]) && is_string($data[$key])) {
            return $data[$key];
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->deepFind($value, $key, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
