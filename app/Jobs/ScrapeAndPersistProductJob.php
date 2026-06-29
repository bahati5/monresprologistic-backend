<?php

namespace App\Jobs;

use App\Enums\AssistedPurchaseStatus;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\Setting;
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
        if (! $item || ! $item->url) {
            return;
        }

        try {
            $result = $scraper->scrape($item->url);

            $updates = [];
            $payload = $this->mergeItemOptions($item);

            if ($result->success && $result->name) {
                $updates['name'] = $result->name;
            }

            if ($result->success) {
                $payload['scrape_status'] = 'success';
                $payload['scraped_at'] = now()->toIso8601String();
            }

            if ($result->merchant) {
                $payload['scraped_merchant'] = $result->merchant;
            }
            if ($result->currency) {
                $payload['scraped_currency'] = $result->currency;
                $payload['currency_original'] = $result->currency;
            }
            if ($result->imageUrl) {
                $payload['scraped_image'] = $result->imageUrl;
            }

            if ($result->price !== null && $result->price > 0) {
                $rawMult = (float) Setting::getValue('quote_scraped_price_to_primary_multiplier', '1');
                $multiplier = (is_finite($rawMult) && $rawMult > 0) ? $rawMult : 1.0;
                $original = round((float) $result->price, 2);
                $converted = round($original * $multiplier, 2);
                $updates['unit_price'] = $converted;
                $payload['price_displayed'] = $original;
                $payload['price_converted'] = $converted;
            }

            if ($result->success || ($result->price !== null && $result->price > 0) || $result->merchant) {
                $updates['options'] = json_encode($payload);
            }

            if (! empty($updates)) {
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

            $failPayload = array_merge($this->mergeItemOptions($item), [
                'scrape_status' => 'failed',
                'scrape_error' => $e->getMessage(),
                'scraped_at' => now()->toIso8601String(),
            ]);

            $item->update([
                'options' => json_encode($failPayload),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeItemOptions(AssistedPurchaseItem $item): array
    {
        $raw = $item->options;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function checkAllItemsScraped(int $purchaseId): void
    {
        $purchase = AssistedPurchase::find($purchaseId);
        if (! $purchase) {
            return;
        }

        $items = $purchase->items;
        $allScraped = $items->every(function ($item) {
            $options = is_string($item->options) ? json_decode($item->options, true) : $item->options;
            $status = is_array($options) ? ($options['scrape_status'] ?? null) : null;

            return $status === 'success' || $status === 'failed';
        });

        if ($allScraped && $purchase->status === AssistedPurchaseStatus::PENDING_QUOTE) {
            $hasInfo = ! empty(json_decode($purchase->line_notes ?? '{}', true));
            if ($hasInfo) {
                Log::info("All items scraped for AP#{$purchaseId} — ready for quoting");
            }
        }
    }
}
