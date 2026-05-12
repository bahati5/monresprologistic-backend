<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductScraperService
{
    public function scrape(string $url): ProductData
    {
        try {
            $locale = $this->detectLocaleFromUrl($url);
            $cookies = $this->buildLocaleCookies($url, $locale);
            $timeout = MerchantParserFactory::isChineseSite($url) ? 12 : 8;

            $request = Http::timeout($timeout)
                ->withoutVerifying()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                    'Accept-Language' => $locale['accept_language'],
                    'Cache-Control' => 'no-cache',
                    'Pragma' => 'no-cache',
                    'Sec-Ch-Ua' => '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
                    'Sec-Ch-Ua-Mobile' => '?0',
                    'Sec-Ch-Ua-Platform' => '"macOS"',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                ]);

            if ($cookies) {
                $request = $request->withHeaders(['Cookie' => $cookies]);
            }

            $response = $request->get($url);

            if (! $response->successful()) {
                Log::warning('Product scraping: HTTP non réussi', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return new ProductData(success: false);
            }

            $html = $response->body();
            $parser = MerchantParserFactory::for($url);
            $parserClass = $parser::class;

            $data = $parser->parse($html, $url);

            if (! $data->success) {
                Log::warning('Product scraping: page récupérée mais données insuffisantes', [
                    'url' => $url,
                    'parser' => $parserClass,
                    'has_name' => $data->name !== null && $data->name !== '',
                    'has_price' => $data->price !== null,
                ]);
            }

            return $data;
        } catch (\Throwable $e) {
            Log::warning("Product scraping failed for {$url}: {$e->getMessage()}", [
                'url' => $url,
                'exception' => $e::class,
            ]);

            return new ProductData(success: false);
        }
    }

    /**
     * Detect desired locale from URL subdomain (e.g. fr.aliexpress.com → fr_FR).
     *
     * @return array{lang: string, region: string, accept_language: string}
     */
    private function detectLocaleFromUrl(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        $subdomainMap = [
            'fr' => ['lang' => 'fr', 'region' => 'FR', 'accept_language' => 'fr-FR,fr;q=0.9,en;q=0.5'],
            'es' => ['lang' => 'es', 'region' => 'ES', 'accept_language' => 'es-ES,es;q=0.9,en;q=0.5'],
            'de' => ['lang' => 'de', 'region' => 'DE', 'accept_language' => 'de-DE,de;q=0.9,en;q=0.5'],
            'it' => ['lang' => 'it', 'region' => 'IT', 'accept_language' => 'it-IT,it;q=0.9,en;q=0.5'],
            'pt' => ['lang' => 'pt', 'region' => 'BR', 'accept_language' => 'pt-BR,pt;q=0.9,en;q=0.5'],
            'nl' => ['lang' => 'nl', 'region' => 'NL', 'accept_language' => 'nl-NL,nl;q=0.9,en;q=0.5'],
        ];

        $parts = explode('.', $host);
        $subdomain = $parts[0] ?? '';

        if (isset($subdomainMap[$subdomain])) {
            return $subdomainMap[$subdomain];
        }

        return ['lang' => 'fr', 'region' => 'FR', 'accept_language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7'];
    }

    /**
     * Build locale cookies for known merchants that use cookies to determine language.
     */
    private function buildLocaleCookies(string $url, array $locale): ?string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $domain = preg_replace('/^www\./', '', $host);

        if (preg_match('/aliexpress\.(com|us|ru)$/', $domain) || str_ends_with($domain, '.aliexpress.com')) {
            $siteMap = [
                'fr' => 'fra', 'es' => 'esp', 'de' => 'deu',
                'it' => 'ita', 'pt' => 'bra', 'nl' => 'nld',
            ];
            $site = $siteMap[$locale['lang']] ?? 'fra';
            $bLocale = $locale['lang'] . '_' . $locale['region'];

            return implode('; ', [
                "aep_usuc_f=site={$site}&c_tp=EUR&region={$locale['region']}&b_locale={$bLocale}",
                "intl_locale={$bLocale}",
                "xman_f=lang={$locale['lang']}",
            ]);
        }

        if (str_contains($domain, 'amazon')) {
            $langMap = ['fr' => 'fr_FR', 'de' => 'de_DE', 'es' => 'es_ES', 'it' => 'it_IT'];
            $lc = $langMap[$locale['lang']] ?? 'fr_FR';

            return "lc-main={$lc}";
        }

        if (str_ends_with($domain, '1688.com') || str_ends_with($domain, 'taobao.com') || str_ends_with($domain, 'tmall.com')) {
            return 'thw=cn; t=0; hng=CN|zh-CN|CNY|156';
        }

        return null;
    }
}
