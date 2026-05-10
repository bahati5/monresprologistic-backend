<?php

namespace App\Jobs;

use App\Services\Scraping\ProductData;
use App\Services\Scraping\ProductScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScrapeProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 5;

    public function __construct(
        public readonly string $url,
        public readonly string $cacheKey,
    ) {}

    public function handle(ProductScraperService $scraper): void
    {
        try {
            $result = $scraper->scrape($this->url);
            Cache::put($this->cacheKey, $result->toArray(), now()->addMinutes(30));
        } catch (\Throwable $e) {
            Log::error('ScrapeProductJob: exception', [
                'url' => $this->url,
                'cache_key' => $this->cacheKey,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            Cache::put($this->cacheKey, (new ProductData(success: false))->toArray(), now()->addMinutes(30));
        }
    }
}
