<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteDynamicTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $client;

    private Agency $agency;

    private AssistedPurchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::factory()->create();
        $this->staff = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->staff->givePermissionTo('manage_assisted_purchases');

        $this->client = User::factory()->create(['agency_id' => $this->agency->id]);

        $this->purchase = AssistedPurchase::create([
            'user_id' => $this->client->id,
            'status' => AssistedPurchaseStatus::PENDING_QUOTE,
            'product_url' => 'https://example.com/product',
            'article_label' => 'Test Product',
            'quantity' => 1,
        ]);

        AssistedPurchaseItem::create([
            'assisted_purchase_id' => $this->purchase->id,
            'url' => 'https://example.com/product',
            'name' => 'Test Product',
            'quantity' => 1,
            'unit_price' => 0,
        ]);
    }

    public function test_can_send_dynamic_quote(): void
    {
        $item = $this->purchase->items->first();

        $response = $this->actingAs($this->staff)->postJson(
            "/api/assisted-purchases/{$this->purchase->id}/quote-dynamic",
            [
                'items' => [
                    [
                        'id' => $item->id,
                        'unit_price' => 100,
                        'availability_status' => 'exact',
                        'alternative_note' => null,
                    ],
                ],
                'lines' => [
                    [
                        'internal_code' => 'COMMISSION',
                        'name' => 'Commission',
                        'type' => 'percentage',
                        'calculation_base' => 'product_price',
                        'value' => 10,
                        'is_visible_to_client' => true,
                    ],
                    [
                        'internal_code' => 'SHIPPING',
                        'name' => 'Fret international',
                        'type' => 'fixed_amount',
                        'calculation_base' => null,
                        'value' => 25,
                        'is_visible_to_client' => true,
                    ],
                ],
                'is_urgent' => false,
                'estimated_delivery' => '7-10 jours',
                'staff_message' => 'Produit vérifié et disponible.',
            ]
        );

        $response->assertOk()
            ->assertJsonStructure(['message', 'snapshot_id', 'total']);

        $this->purchase->refresh();
        $this->assertEquals(AssistedPurchaseStatus::QUOTED, $this->purchase->status);
        $this->assertNotNull($this->purchase->quoted_at);
        $this->assertEquals(1, $this->purchase->quote_version);

        $this->assertDatabaseHas('quote_snapshots', [
            'assisted_purchase_id' => $this->purchase->id,
            'version' => 1,
            'client_response' => 'pending',
        ]);
    }

    public function test_blocks_send_with_unchecked_articles(): void
    {
        $item = $this->purchase->items->first();

        $response = $this->actingAs($this->staff)->postJson(
            "/api/assisted-purchases/{$this->purchase->id}/quote-dynamic",
            [
                'items' => [
                    [
                        'id' => $item->id,
                        'unit_price' => 100,
                        'availability_status' => 'not_checked',
                        'alternative_note' => null,
                    ],
                ],
                'lines' => [
                    ['internal_code' => 'FEE', 'name' => 'Fee', 'type' => 'fixed_amount', 'calculation_base' => null, 'value' => 5, 'is_visible_to_client' => true],
                ],
            ]
        );

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Tous les articles doivent être vérifiés avant envoi.');
    }

    public function test_blocks_send_without_alternative_note(): void
    {
        $item = $this->purchase->items->first();

        $response = $this->actingAs($this->staff)->postJson(
            "/api/assisted-purchases/{$this->purchase->id}/quote-dynamic",
            [
                'items' => [
                    [
                        'id' => $item->id,
                        'unit_price' => 100,
                        'availability_status' => 'available_alternative',
                        'alternative_note' => '',
                    ],
                ],
                'lines' => [
                    ['internal_code' => 'FEE', 'name' => 'Fee', 'type' => 'fixed_amount', 'calculation_base' => null, 'value' => 5, 'is_visible_to_client' => true],
                ],
            ]
        );

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Une note explicative est requise pour les articles en alternative.');
    }

    public function test_can_create_revision(): void
    {
        $this->purchase->update([
            'status' => AssistedPurchaseStatus::QUOTED,
            'quoted_at' => now(),
            'quote_version' => 1,
        ]);

        $response = $this->actingAs($this->staff)->postJson(
            "/api/assisted-purchases/{$this->purchase->id}/revision",
            ['reason' => 'Client a demandé un changement de taille']
        );

        $response->assertOk()
            ->assertJsonPath('version', 2);

        $this->purchase->refresh();
        $this->assertEquals(2, $this->purchase->quote_version);
    }

    public function test_blocks_revision_beyond_v3(): void
    {
        $this->purchase->update([
            'status' => AssistedPurchaseStatus::QUOTED,
            'quoted_at' => now(),
            'quote_version' => 3,
        ]);

        $response = $this->actingAs($this->staff)->postJson(
            "/api/assisted-purchases/{$this->purchase->id}/revision",
            ['reason' => 'One more try']
        );

        $response->assertStatus(422);
    }

    public function test_can_send_clarification(): void
    {
        $response = $this->actingAs($this->staff)->postJson(
            "/api/assisted-purchases/{$this->purchase->id}/clarification",
            [
                'message' => 'Pouvez-vous préciser la taille souhaitée ?',
                'channels' => ['email'],
            ]
        );

        $response->assertOk();
        $this->purchase->refresh();
        $this->assertNotNull($this->purchase->clarification_sent_at);
        $this->assertEquals('Pouvez-vous préciser la taille souhaitée ?', $this->purchase->clarification_message);
    }
}
