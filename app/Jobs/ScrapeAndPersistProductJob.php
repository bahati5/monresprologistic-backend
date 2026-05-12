<?php

namespace App\Jobs;

use App\Enums\AssistedPurchaseStatus;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Services\Scraping\ProductScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * §3.6 — Scrape a product URL and persist results directly to the AssistedPurchaseItem.
 * Dispatched automatically after purchase creation (all channels).
 */
class ScrapeAndPersistProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly int $itemId,
    ) {}

    public function handle(ProductScraperService $scraper): void
    {
        $item = AssistedPurchaseItem::find($this->itemId);
        if (!$item || !$item->url) {
            return;
        }

        try {
            $result = $scraper->scrape($item->url);

            $updates = [];
            if ($result->success && $result->name) {
                $updates['name'] = $result->name;
            }
            if ($result->price !== null && $result->price > 0) {
                $updates['unit_price'] = $result->price;
            }
            if ($result->merchant) {
                $updates['options'] = json_encode([
                    'scraped_merchant' => $result->merchant,
                    'scraped_currency' => $result->currency,
                    'scraped_image' => $result->imageUrl,
                    'scraped_at' => now()->toIso8601String(),
                    'scrape_status' => 'success',
                ]);
            }

            if (!empty($updates)) {
                $item->update($updates);
            }

            $this->checkAllItemsScraped($item->assisted_purchase_id);

            Log::info("ScrapeAndPersist: item #{$this->itemId} updated", [
                'success' => $result->success,
                'name' => $result->name,
                'price' => $result->price,
            ]);
        } catch (\Throwable $e) {
            Log::warning("ScrapeAndPersist: failed for item #{$this->itemId}: {$e->getMessage()}");

            $item->update([
                'options' => json_encode([
                    'scrape_status' => 'failed',
                    'scrape_error' => $e->getMessage(),
                    'scraped_at' => now()->toIso8601String(),
                ]),
            ]);
        }
    }

    private function checkAllItemsScraped(int $purchaseId): void
    {
        $purchase = AssistedPurchase::find($purchaseId);
        if (!$purchase) {
            return;
        }

        $items = $purchase->items;
        $allScraped = $items->every(function ($item) {
            $options = is_string($item->options) ? json_decode($item->options, true) : $item->options;
            $status = $options['scrape_status'] ?? null;
            return $status === 'success' || $status === 'failed';
        });

        if ($allScraped && $purchase->status === AssistedPurchaseStatus::PENDING_QUOTE) {
            $hasInfo = !empty(json_decode($purchase->line_notes ?? '{}', true));
            if ($hasInfo) {
                Log::info("All items scraped for AP#{$purchaseId} — ready for quoting");
            }
        }
    }
}
