<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteAnalyticsTest extends TestCase
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

    public function test_can_get_analytics(): void
    {
        $client = User::factory()->create(['agency_id' => $this->agency->id]);

        AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::PAID,
            'product_url' => 'https://example.com',
            'article_label' => 'Test',
            'quantity' => 1,
            'quoted_at' => now(),
            'total_amount' => 250,
        ]);

        AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::CANCELLED,
            'product_url' => 'https://example.com/2',
            'article_label' => 'Test 2',
            'quantity' => 1,
            'quoted_at' => now(),
            'total_amount' => 100,
            'refusal_reason' => 'too_expensive',
        ]);

        $response = $this->actingAs($this->staff)->getJson('/api/analytics/assisted-purchase?from=' . now()->subDays(30)->toDateString() . '&to=' . now()->toDateString());

        $response->assertOk()
            ->assertJsonStructure([
                'total_quoted',
                'total_accepted',
                'acceptance_rate',
                'total_revenue',
                'avg_response_hours',
                'weekly_data',
                'top_merchants',
                'refusal_reasons',
                'reminder_efficiency',
                'clarification_count',
                'period',
            ]);

        $this->assertEquals(2, $response->json('total_quoted'));
        $this->assertEquals(1, $response->json('total_accepted'));
    }
}
