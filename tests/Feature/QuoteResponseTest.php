<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\QuoteSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteResponseTest extends TestCase
{
    use RefreshDatabase;

    private AssistedPurchase $purchase;

    private QuoteSnapshot $snapshot;

    protected function setUp(): void
    {
        parent::setUp();

        $agency = Agency::factory()->create();
        $client = User::factory()->create(['agency_id' => $agency->id]);

        $this->purchase = AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com/product',
            'article_label' => 'Test Product',
            'quantity' => 1,
            'quoted_at' => now(),
            'total_amount' => 135.00,
            'quote_expires_at' => now()->addDays(7),
        ]);

        $this->snapshot = QuoteSnapshot::create([
            'assisted_purchase_id' => $this->purchase->id,
            'version' => 1,
            'snapshot_data' => ['lines' => [], 'subtotal' => 100, 'total_primary' => 135],
            'articles_data' => [['name' => 'Test', 'unit_price' => 100, 'quantity' => 1]],
            'total_primary' => 135.00,
            'primary_currency' => 'USD',
            'sent_at' => now(),
            'expires_at' => now()->addDays(7),
            'response_token' => 'test_token_abc123',
            'client_response' => 'pending',
        ]);
    }

    public function test_can_verify_valid_token(): void
    {
        $response = $this->getJson('/api/quotes/verify-token?token=test_token_abc123');

        $response->assertOk()
            ->assertJsonPath('status', 'valid')
            ->assertJsonStructure(['quote' => ['reference', 'total', 'currency']]);
    }

    public function test_invalid_token_returns_404(): void
    {
        $response = $this->getJson('/api/quotes/verify-token?token=invalid_token');

        $response->assertNotFound();
    }

    public function test_expired_token_returns_expired_status(): void
    {
        $this->snapshot->update(['expires_at' => now()->subDay()]);

        $response = $this->getJson('/api/quotes/verify-token?token=test_token_abc123');

        $response->assertOk()
            ->assertJsonPath('status', 'expired');
    }

    public function test_can_accept_quote(): void
    {
        $response = $this->postJson('/api/quotes/respond', [
            'token' => 'test_token_abc123',
            'response' => 'accepted',
        ]);

        $response->assertOk()
            ->assertJsonPath('response', 'accepted');

        $this->purchase->refresh();
        $this->assertEquals(AssistedPurchaseStatus::AWAITING_PAYMENT, $this->purchase->status);

        $this->snapshot->refresh();
        $this->assertEquals('accepted', $this->snapshot->client_response);
        $this->assertNotNull($this->snapshot->responded_at);
    }

    public function test_can_refuse_quote_with_reason(): void
    {
        $response = $this->postJson('/api/quotes/respond', [
            'token' => 'test_token_abc123',
            'response' => 'refused',
            'refusal_reason' => 'too_expensive',
            'refusal_note' => 'Le prix est au-dessus de mon budget.',
        ]);

        $response->assertOk()
            ->assertJsonPath('response', 'refused');

        $this->purchase->refresh();
        $this->assertEquals(AssistedPurchaseStatus::CANCELLED, $this->purchase->status);
        $this->assertEquals('too_expensive', $this->purchase->refusal_reason);
    }

    public function test_cannot_respond_twice(): void
    {
        $this->snapshot->update(['client_response' => 'accepted', 'responded_at' => now()]);

        $response = $this->postJson('/api/quotes/respond', [
            'token' => 'test_token_abc123',
            'response' => 'refused',
            'refusal_reason' => 'changed_mind',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Réponse déjà enregistrée.');
    }

    public function test_cannot_respond_to_expired_quote(): void
    {
        $this->snapshot->update(['expires_at' => now()->subDay()]);

        $response = $this->postJson('/api/quotes/respond', [
            'token' => 'test_token_abc123',
            'response' => 'accepted',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Devis expiré.');
    }
}
