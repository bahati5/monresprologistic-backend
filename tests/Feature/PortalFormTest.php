<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Portail authentifié — création d’achats assistés (POST /api/assisted-purchases).
 * FORM-001 à FORM-006.
 */
class PortalFormTest extends TestCase
{
    use RefreshDatabase;

    protected Agency $agency;

    protected User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->agency = Agency::factory()->create();
        $this->client = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->client->assignRole('client');
    }

    protected function validStorePayload(): array
    {
        return [
            'notes' => 'Notes portail',
            'items' => [
                [
                    'url' => 'https://example.com/product-a',
                    'name' => 'Article A',
                    'quantity' => 2,
                    'options' => 'Taille M',
                ],
                [
                    'url' => 'https://example.org/product-b',
                    'name' => 'Article B',
                    'quantity' => 1,
                ],
            ],
        ];
    }

    /** FORM-001 : utilisateur authentifié peut créer un achat assisté. */
    public function test_form_001_authenticated_user_can_create_assisted_purchase(): void
    {
        Sanctum::actingAs($this->client);

        $response = $this->postJson('/api/assisted-purchases', $this->validStorePayload());

        $response->assertOk()
            ->assertJsonPath('message', 'Demande d’achat assisté envoyée.')
            ->assertJsonPath('purchase.status', AssistedPurchaseStatus::PENDING_QUOTE->value);

        $this->assertDatabaseCount('assisted_purchases', 1);
        $this->assertDatabaseCount('assisted_purchase_items', 2);
    }

    /** FORM-002 : requête non authentifiée → 401. */
    public function test_form_002_unauthenticated_returns_401(): void
    {
        $response = $this->postJson('/api/assisted-purchases', $this->validStorePayload());

        $response->assertUnauthorized();
    }

    /**
     * FORM-003 : validation — l’URL produit (items.*.url) doit être une URL valide.
     * Le parent enregistre la première ligne dans product_url.
     */
    public function test_form_003_validation_product_url_must_be_valid_url(): void
    {
        Sanctum::actingAs($this->client);

        $payload = $this->validStorePayload();
        $payload['items'][0]['url'] = 'not-a-valid-url';

        $response = $this->postJson('/api/assisted-purchases', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.url']);
    }

    /** FORM-004 : validation — quantity doit être un entier ≥ 1. */
    public function test_form_004_validation_quantity_must_be_positive_integer(): void
    {
        Sanctum::actingAs($this->client);

        $payload = $this->validStorePayload();
        $payload['items'][0]['quantity'] = 0;

        $response = $this->postJson('/api/assisted-purchases', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    /** FORM-005 : la demande créée est au statut PENDING_QUOTE. */
    public function test_form_005_created_purchase_has_pending_quote_status(): void
    {
        Sanctum::actingAs($this->client);

        $this->postJson('/api/assisted-purchases', $this->validStorePayload())->assertOk();

        $purchase = AssistedPurchase::query()->first();
        $this->assertNotNull($purchase);
        $this->assertSame(AssistedPurchaseStatus::PENDING_QUOTE, $purchase->status);
    }

    /** FORM-006 : les lignes AssistedPurchaseItem reflètent les données envoyées. */
    public function test_form_006_items_created_with_correct_data(): void
    {
        Sanctum::actingAs($this->client);

        $this->postJson('/api/assisted-purchases', $this->validStorePayload())->assertOk();

        $purchase = AssistedPurchase::query()->first();
        $this->assertNotNull($purchase);

        $this->assertSame('https://example.com/product-a', $purchase->product_url);
        $this->assertSame('Article A', $purchase->article_label);
        $this->assertSame(2, $purchase->quantity);

        $items = AssistedPurchaseItem::query()->where('assisted_purchase_id', $purchase->id)->orderBy('id')->get();
        $this->assertCount(2, $items);

        $this->assertSame('https://example.com/product-a', $items[0]->url);
        $this->assertSame('Article A', $items[0]->name);
        $this->assertSame(2, $items[0]->quantity);
        $this->assertSame('Taille M', $items[0]->options);

        $this->assertSame('https://example.org/product-b', $items[1]->url);
        $this->assertSame('Article B', $items[1]->name);
        $this->assertSame(1, $items[1]->quantity);
    }
}
