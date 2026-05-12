<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::factory()->create();
        $this->staff = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->staff->givePermissionTo('manage_assisted_purchases');
    }

    public function test_can_get_dashboard_metrics(): void
    {
        $client = User::factory()->create(['agency_id' => $this->agency->id]);

        AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com',
            'article_label' => 'Test',
            'quantity' => 1,
            'quoted_at' => now(),
            'total_amount' => 200,
        ]);

        $response = $this->actingAs($this->staff)->getJson('/api/quotes/dashboard/metrics');

        $response->assertOk()
            ->assertJsonStructure(['open_quotes', 'pending_value', 'acceptance_rate', 'avg_response_hours']);

        $this->assertEquals(1, $response->json('open_quotes'));
    }

    public function test_can_list_dashboard_quotes(): void
    {
        $client = User::factory()->create(['agency_id' => $this->agency->id]);

        AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com',
            'article_label' => 'Test',
            'quantity' => 1,
            'quoted_at' => now(),
            'total_amount' => 150,
        ]);

        $response = $this->actingAs($this->staff)->getJson('/api/quotes/dashboard/list?tab=all');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_can_prolong_expired_quote(): void
    {
        $client = User::factory()->create(['agency_id' => $this->agency->id]);

        $purchase = AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::EXPIRED,
            'product_url' => 'https://example.com',
            'article_label' => 'Test',
            'quantity' => 1,
            'quoted_at' => now()->subDays(10),
            'quote_expires_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/quotes/{$purchase->id}/prolong", [
            'additional_days' => 7,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['new_expires_at']);

        $purchase->refresh();
        $this->assertEquals(AssistedPurchaseStatus::QUOTED, $purchase->status);
    }

    public function test_can_cancel_reminders(): void
    {
        $client = User::factory()->create(['agency_id' => $this->agency->id]);

        $purchase = AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com',
            'article_label' => 'Test',
            'quantity' => 1,
            'quoted_at' => now(),
            'reminder_count' => 1,
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/quotes/{$purchase->id}/cancel-reminders");

        $response->assertOk();
        $purchase->refresh();
        $this->assertEquals(99, $purchase->reminder_count);
    }
}
