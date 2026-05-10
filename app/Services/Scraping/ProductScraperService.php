<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductScraperService
{
    public function scrape(string $url): ProductData
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                ])
                ->get($url);

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
}
