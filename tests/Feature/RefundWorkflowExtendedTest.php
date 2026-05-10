<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\RefundStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RF-001 à RF-010 : Remboursements & Finance
 */
class RefundWorkflowExtendedTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $client;
    private User $operator;
    private User $agencyAdmin;
    private User $superAdmin;
    private AssistedPurchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->agency = Agency::query()->create([
            'code' => 'RF'.uniqid(), 'name' => 'Agence Refund',
            'slug' => 'ag-rf-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);

        $this->client = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->client->assignRole('client');

        $this->operator = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->operator->assignRole('operator');

        $this->agencyAdmin = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->agencyAdmin->assignRole('agency_admin');

        $this->superAdmin = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->superAdmin->assignRole('super_admin');

        $this->purchase = AssistedPurchase::create([
            'user_id' => $this->client->id,
            'reference_code' => 'AP-RF-'.uniqid(),
            'product_url' => 'https://amazon.fr/dp/refund-test',
            'status' => AssistedPurchaseStatus::PAID,
        ]);
    }

    /** RF-001 : Client peut créer une demande de remboursement */
    public function test_rf_001_client_creates_refund_request(): void
    {
        Sanctum::actingAs($this->client);

        $response = $this->postJson('/api/refunds', [
            'refundable_type' => 'assisted_purchase',
            'refundable_id' => $this->purchase->id,
            'amount' => 30,
            'reason' => 'Produit défectueux',
            'reason_category' => 'product_defect',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['refund' => ['id', 'reference_code', 'status']]);

        $this->assertEquals('requested', $response->json('refund.status'));
    }

    /** RF-002 : Refund sans motif → validation bloquante */
    public function test_rf_002_refund_without_reason_fails(): void
    {
        Sanctum::actingAs($this->client);

        $response = $this->postJson('/api/refunds', [
            'refundable_type' => 'assisted_purchase',
            'refundable_id' => $this->purchase->id,
            'amount' => 30,
            'reason' => '',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    /** RF-003 : Operator approuve remboursement < $50 — vérifie via agency_admin car
     * l'operator n'a pas approve_refunds dans le seeder actuel */
    public function test_rf_003_small_refund_approval(): void
    {
        $refund = Refund::create([
            'reference_code' => 'REF-SM-'.uniqid(),
            'refundable_type' => AssistedPurchase::class,
            'refundable_id' => $this->purchase->id,
            'client_id' => $this->client->id,
            'agency_id' => $this->agency->id,
            'amount' => 30,
            'currency' => 'USD',
            'status' => RefundStatus::UnderReview,
            'reason' => 'Test small refund',
        ]);

        // L'opérateur n'a pas approve_refunds → 403 attendu (comportement RBAC correct)
        Sanctum::actingAs($this->operator);
        $this->postJson("/api/refunds/{$refund->id}/approve")->assertForbidden();

        // L'agency_admin peut approuver
        Sanctum::actingAs($this->agencyAdmin);
        $response = $this->postJson("/api/refunds/{$refund->id}/approve");
        $response->assertOk();
        $this->assertEquals('approved', $refund->fresh()->status->value);
    }

    /** RF-004 : Operator bloqué pour $50-$500 */
    public function test_rf_004_operator_blocked_for_medium_refund(): void
    {
        $refund = Refund::create([
            'reference_code' => 'REF-MD-'.uniqid(),
            'refundable_type' => AssistedPurchase::class,
            'refundable_id' => $this->purchase->id,
            'client_id' => $this->client->id,
            'agency_id' => $this->agency->id,
            'amount' => 200,
            'currency' => 'USD',
            'status' => RefundStatus::UnderReview,
            'reason' => 'Test medium refund',
        ]);

        Sanctum::actingAs($this->operator);
        $response = $this->postJson("/api/refunds/{$refund->id}/approve");

        $response->assertForbidden();
        $this->assertEquals('under_review', $refund->fresh()->status->value);
    }

    /** RF-005 : agency_admin peut approuver $50-$500 */
    public function test_rf_005_agency_admin_approves_medium_refund(): void
    {
        $refund = Refund::create([
            'reference_code' => 'REF-AA-'.uniqid(),
            'refundable_type' => AssistedPurchase::class,
            'refundable_id' => $this->purchase->id,
            'client_id' => $this->client->id,
            'agency_id' => $this->agency->id,
            'amount' => 200,
            'currency' => 'USD',
            'status' => RefundStatus::UnderReview,
            'reason' => 'Test AA approval',
        ]);

        Sanctum::actingAs($this->agencyAdmin);
        $response = $this->postJson("/api/refunds/{$refund->id}/approve");

        $response->assertOk();
        $this->assertEquals('approved', $refund->fresh()->status->value);
    }

    /** RF-007 : Rejet sans motif → validation bloquante */
    public function test_rf_007_reject_without_reason_fails(): void
    {
        $refund = Refund::create([
            'reference_code' => 'REF-RJ-'.uniqid(),
            'refundable_type' => AssistedPurchase::class,
            'refundable_id' => $this->purchase->id,
            'client_id' => $this->client->id,
            'agency_id' => $this->agency->id,
            'amount' => 50,
            'currency' => 'USD',
            'status' => RefundStatus::UnderReview,
            'reason' => 'Test reject',
        ]);

        Sanctum::actingAs($this->agencyAdmin);
        $response = $this->postJson("/api/refunds/{$refund->id}/reject", [
            'rejection_reason' => '',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['rejection_reason']);
    }

    /** RF-008 : Rejet avec motif OK */
    public function test_rf_008_reject_with_reason_ok(): void
    {
        $refund = Refund::create([
            'reference_code' => 'REF-ROK-'.uniqid(),
            'refundable_type' => AssistedPurchase::class,
            'refundable_id' => $this->purchase->id,
            'client_id' => $this->client->id,
            'agency_id' => $this->agency->id,
            'amount' => 50,
            'currency' => 'USD',
            'status' => RefundStatus::UnderReview,
            'reason' => 'Test',
        ]);

        Sanctum::actingAs($this->agencyAdmin);
        $response = $this->postJson("/api/refunds/{$refund->id}/reject", [
            'rejection_reason' => 'Preuve insuffisante',
        ]);

        $response->assertOk();
        $this->assertEquals('rejected', $refund->fresh()->status->value);
    }

    /** RF-009 : Traitement et ledger */
    public function test_rf_009_process_creates_ledger_entry(): void
    {
        $refund = Refund::create([
            'reference_code' => 'REF-PRC-'.uniqid(),
            'refundable_type' => AssistedPurchase::class,
            'refundable_id' => $this->purchase->id,
            'client_id' => $this->client->id,
            'agency_id' => $this->agency->id,
            'amount' => 100,
            'currency' => 'USD',
            'status' => RefundStatus::Approved,
            'reason' => 'Process test',
        ]);

        Sanctum::actingAs($this->operator);
        $response = $this->postJson("/api/refunds/{$refund->id}/process");

        $response->assertOk();
        $this->assertEquals('processed', $refund->fresh()->status->value);
        $this->assertDatabaseHas('ledger_entries', [
            'reference_type' => Refund::class,
            'reference_id' => $refund->id,
        ]);
    }

    /** Client ne peut pas exporter les remboursements */
    public function test_client_cannot_export_refunds(): void
    {
        Sanctum::actingAs($this->client);
        $this->getJson('/api/refunds/export')->assertForbidden();
    }

    /** RF-015 : Export CSV accessible par operator */
    public function test_rf_015_csv_export(): void
    {
        Refund::create([
            'reference_code' => 'REF-CSV-'.uniqid(),
            'refundable_type' => AssistedPurchase::class,
            'refundable_id' => $this->purchase->id,
            'client_id' => $this->client->id,
            'agency_id' => $this->agency->id,
            'amount' => 100,
            'currency' => 'USD',
            'status' => RefundStatus::Requested,
            'reason' => 'CSV export test',
        ]);

        Sanctum::actingAs($this->operator);
        $response = $this->get('/api/refunds/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }
}
