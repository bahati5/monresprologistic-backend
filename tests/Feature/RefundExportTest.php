<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\RefundStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RefundExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'RFX'.uniqid(),
            'name' => 'Agence test',
            'slug' => 'ag-rfx-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_client_cannot_export_refunds(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        Sanctum::actingAs($client);

        $this->getJson('/api/refunds/export')->assertForbidden();
    }

    public function test_operator_receives_csv_export(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');
        $c = User::factory()->create(['agency_id' => $agency->id]);
        $c->assignRole('client');

        $purchase = AssistedPurchase::query()->create([
            'user_id' => $c->id,
            'status' => AssistedPurchaseStatus::PAID,
            'product_url' => 'https://example.com/p',
            'article_label' => 'Article test',
        ]);

        Refund::query()->create([
            'reference_code' => 'RMB-TEST-0001',
            'refundable_type' => AssistedPurchase::class,
            'refundable_id' => $purchase->id,
            'client_id' => $c->id,
            'agency_id' => $agency->id,
            'amount' => 12.5,
            'currency' => 'USD',
            'status' => RefundStatus::Requested,
            'reason' => 'Test export',
        ]);

        Sanctum::actingAs($operator);

        $response = $this->call('GET', '/api/refunds/export', server: $this->transformHeadersToServerVars([
            'Accept' => 'text/csv',
        ]));

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertNotEmpty($body);
        $this->assertStringContainsString('RMB-TEST-0001', $body);
    }
}
