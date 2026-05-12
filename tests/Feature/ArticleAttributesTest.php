<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ATTR-001 to ATTR-008 — article availability / attributes on assisted purchase lines.
 */
class ArticleAttributesTest extends TestCase
{
    use RefreshDatabase;

    private function makePurchase(): AssistedPurchase
    {
        $agency = Agency::factory()->create([
            'code' => 'ATTR'.uniqid(),
            'name' => 'Agence ATTR',
            'slug' => 'attr-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['agency_id' => $agency->id]);

        return AssistedPurchase::query()->create([
            'user_id' => $user->id,
            'status' => AssistedPurchaseStatus::PENDING_QUOTE,
            'product_url' => 'https://merchant.example/parent',
            'quantity' => 1,
        ]);
    }

    /** ATTR-001: AssistedPurchaseItem can be created with valid data */
    public function test_attr001_item_can_be_created_with_valid_data(): void
    {
        $purchase = $this->makePurchase();

        $item = AssistedPurchaseItem::query()->create([
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://shop.example.com/article/1',
            'name' => 'Sample article',
            'quantity' => 3,
            'unit_price' => 19.99,
            'options' => json_encode(['note' => 'ok']),
        ]);

        $this->assertDatabaseHas('assisted_purchase_items', [
            'id' => $item->id,
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://shop.example.com/article/1',
            'name' => 'Sample article',
            'quantity' => 3,
        ]);
        $this->assertSame($purchase->id, $item->assistedPurchase->id);
    }

    /** ATTR-002: Item options JSON stores availability status correctly */
    public function test_attr002_options_stores_availability_status(): void
    {
        $purchase = $this->makePurchase();
        $payload = ['availability_status' => 'unavailable'];

        $item = AssistedPurchaseItem::query()->create([
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://shop.example.com/a',
            'name' => 'A',
            'quantity' => 1,
            'unit_price' => 10,
            'options' => json_encode($payload),
        ]);

        $decoded = json_decode($item->fresh()->options, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('unavailable', $decoded['availability_status']);
    }

    /** ATTR-003: Item options JSON stores alternative product notes */
    public function test_attr003_options_stores_alternative_notes(): void
    {
        $purchase = $this->makePurchase();
        $payload = [
            'alternative_product' => [
                'note' => 'Client may accept model B in red',
                'url' => 'https://shop.example.com/alt-b',
            ],
        ];

        $item = AssistedPurchaseItem::query()->create([
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://shop.example.com/original',
            'name' => 'Original',
            'quantity' => 1,
            'unit_price' => 5,
            'options' => json_encode($payload),
        ]);

        $decoded = json_decode($item->fresh()->options, true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('model B', $decoded['alternative_product']['note']);
        $this->assertSame('https://shop.example.com/alt-b', $decoded['alternative_product']['url']);
    }

    /** ATTR-004: Item options JSON stores scrape results (merchant, currency, image) */
    public function test_attr004_options_stores_scrape_results(): void
    {
        $purchase = $this->makePurchase();
        $payload = [
            'scrape' => [
                'merchant' => 'Example Store',
                'currency' => 'EUR',
                'image' => 'https://cdn.example.com/img.jpg',
            ],
        ];

        $item = AssistedPurchaseItem::query()->create([
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://shop.example.com/scraped',
            'name' => 'Scraped title',
            'quantity' => 2,
            'unit_price' => 12.5,
            'options' => json_encode($payload),
        ]);

        $decoded = json_decode($item->fresh()->options, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Example Store', $decoded['scrape']['merchant']);
        $this->assertSame('EUR', $decoded['scrape']['currency']);
        $this->assertSame('https://cdn.example.com/img.jpg', $decoded['scrape']['image']);
    }

    /** ATTR-005: Items belong to an AssistedPurchase */
    public function test_attr005_item_belongs_to_assisted_purchase(): void
    {
        $purchase = $this->makePurchase();

        $item = AssistedPurchaseItem::query()->create([
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://shop.example.com/x',
            'name' => 'X',
            'quantity' => 1,
            'unit_price' => 1,
        ]);

        $this->assertInstanceOf(AssistedPurchase::class, $item->assistedPurchase);
        $this->assertTrue($item->assistedPurchase->is($purchase));
    }

    /** ATTR-006: Multiple items can belong to one purchase */
    public function test_attr006_multiple_items_on_one_purchase(): void
    {
        $purchase = $this->makePurchase();

        AssistedPurchaseItem::query()->create([
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://shop.example.com/one',
            'name' => 'One',
            'quantity' => 1,
            'unit_price' => 1,
        ]);
        AssistedPurchaseItem::query()->create([
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://shop.example.com/two',
            'name' => 'Two',
            'quantity' => 2,
            'unit_price' => 2,
        ]);

        $purchase->load('items');
        $this->assertCount(2, $purchase->items);
        $this->assertSame(
            ['https://shop.example.com/one', 'https://shop.example.com/two'],
            $purchase->items->sortBy('id')->pluck('url')->values()->all(),
        );
    }

    /**
     * ATTR-007: Missing unit_price is stored as null; effective price for totals is zero.
     */
    public function test_attr007_unit_price_effective_zero_when_omitted(): void
    {
        $purchase = $this->makePurchase();

        $item = AssistedPurchaseItem::query()->create([
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://shop.example.com/free-line',
            'name' => 'No price yet',
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('assisted_purchase_items', [
            'id' => $item->id,
            'quantity' => 2,
            'unit_price' => null,
        ]);
        $this->assertNull($item->fresh()->unit_price);
        $this->assertSame(0.0, (float) ($item->fresh()->unit_price ?? 0));
    }

    /** ATTR-008: Item display label falls back from URL when name is absent */
    public function test_attr008_display_label_fallback_from_url_when_name_missing(): void
    {
        $purchase = $this->makePurchase();

        $item = AssistedPurchaseItem::query()->create([
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://example.com/991',
            'name' => null,
            'quantity' => 1,
        ]);

        $this->assertSame('Produit (example.com)', $item->display_label);
    }
}
