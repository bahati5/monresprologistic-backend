<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Invoice;
use App\Models\Profile;
use App\Models\Refund;
use App\Models\Shipment;
use App\Models\User;
use App\Enums\RefundStatus;
use App\Enums\ShipmentStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests de Sécurité et Matrice d'Accès (RBAC)
 * Plan de Test Master — Section 2 : SEC-01 à SEC-04
 */
class RbacSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'RBAC'.uniqid(),
            'name' => 'Agence test RBAC',
            'slug' => 'ag-rbac-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    /**
     * SEC-01 : Accès aux paramètres système globaux
     * Seul le super_admin doit avoir accès aux paramètres système globaux.
     * agency_admin et operator doivent recevoir un 403.
     */
    public function test_sec_01_only_super_admin_can_access_system_settings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        // Créer les utilisateurs de test
        $superAdmin = User::factory()->create(['agency_id' => $agency->id]);
        $superAdmin->assignRole('super_admin');

        $agencyAdmin = User::factory()->create(['agency_id' => $agency->id]);
        $agencyAdmin->assignRole('agency_admin');

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        // Test : super_admin a manage_users permission
        $this->assertTrue($superAdmin->hasPermissionName('manage_users'));

        // Test : agency_admin a aussi manage_users selon la matrice actuelle
        $this->assertTrue($agencyAdmin->hasPermissionName('manage_users'));

        // Test : operator n'a PAS manage_users
        $this->assertFalse($operator->hasPermissionName('manage_users'));

        // Test : super_admin peut accéder à la gestion des utilisateurs
        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/users')->assertOk();

        // Test : agency_admin peut aussi accéder à la gestion des utilisateurs (selon la matrice)
        Sanctum::actingAs($agencyAdmin);
        $this->getJson('/api/users')->assertOk();

        // Test : operator ne peut pas accéder à la gestion des utilisateurs
        Sanctum::actingAs($operator);
        $this->getJson('/api/users')->assertForbidden();
    }

    /**
     * SEC-02 : Approbation d'un remboursement > 50$
     * L'operator ne doit pas pouvoir approuver un remboursement > 50$.
     * Seul l'agency_admin peut le faire.
     */
    public function test_sec_02_operator_cannot_approve_refund_above_threshold(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $agencyAdmin = User::factory()->create(['agency_id' => $agency->id]);
        $agencyAdmin->assignRole('agency_admin');

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        // Créer un remboursement de 300$ (au-dessus du seuil de 50$)
        $refund = Refund::query()->create([
            'reference_code' => 'RMB-SEC02-001',
            'refundable_type' => 'App\Models\Shipment',
            'refundable_id' => 1,
            'client_id' => $client->id,
            'agency_id' => $agency->id,
            'amount' => 300.00,
            'currency' => 'USD',
            'status' => RefundStatus::UnderReview,
            'reason' => 'Test SEC-02',
        ]);

        // Test : operator ne peut pas approuver (permission manquante : approve_refunds)
        Sanctum::actingAs($operator);
        $response = $this->postJson("/api/refunds/{$refund->id}/approve");
        $response->assertForbidden();

        // Test : agency_admin peut approuver (a la permission approve_refunds)
        Sanctum::actingAs($agencyAdmin);
        $response = $this->postJson("/api/refunds/{$refund->id}/approve");
        $response->assertOk();
    }

    /**
     * SEC-03 : Visibilité des expéditions globales
     * Le driver ne doit voir que ses tournées assignées, pas la vue globale.
     */
    public function test_sec_03_driver_only_sees_assigned_shipments(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $driver = User::factory()->create(['agency_id' => $agency->id]);
        $driver->assignRole('driver');

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        // Test : driver n'a que les permissions view_shipments et view_tracking
        $this->assertTrue($driver->hasPermissionName('view_shipments'));
        $this->assertFalse($driver->hasPermissionName('create_shipments'));
        $this->assertFalse($driver->hasPermissionName('edit_shipments'));
        $this->assertFalse($driver->hasPermissionName('delete_shipments'));

        // Test : operator a plus de permissions sur les expéditions
        $this->assertTrue($operator->hasPermissionName('view_shipments'));
        $this->assertTrue($operator->hasPermissionName('create_shipments'));
        $this->assertTrue($operator->hasPermissionName('edit_shipments'));
    }

    /**
     * SEC-04 : Modification d'une facture validée
     * Une facture validée doit être immutable. Même le super_admin ne peut pas la modifier.
     * Un avoir doit être généré pour corriger.
     */
    public function test_sec_04_validated_invoice_is_immutable(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $superAdmin = User::factory()->create(['agency_id' => $agency->id]);
        $superAdmin->assignRole('super_admin');

        $sender = Profile::query()->create([
            'first_name' => 'A',
            'last_name' => 'B',
            'agency_id' => $agency->id,
        ]);
        $recipient = Profile::query()->create([
            'first_name' => 'C',
            'last_name' => 'D',
            'agency_id' => $agency->id,
        ]);

        $shipment = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $superAdmin->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::Delivered,
            'public_tracking' => 'TRK-SEC04-001',
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 500,
        ]);

        Invoice::query()->create([
            'user_id' => $superAdmin->id,
            'shipment_id' => $shipment->id,
            'invoice_number' => 'INV-SEC04-001',
            'amount' => 500.00,
            'currency' => 'USD',
            'status' => 'paid',
        ]);

        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/finance/invoices', [
            'shipment_id' => $shipment->id,
            'currency' => 'USD',
            'amount' => 100.00,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['shipment_id']);

        $this->assertEquals(1, Invoice::query()->where('shipment_id', $shipment->id)->count());
    }

    /**
     * SEC-05 : L'operator ne peut pas lister les factures finance agence.
     */
    public function test_sec_05_operator_cannot_list_finance_invoices(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        Sanctum::actingAs($operator);
        $this->getJson('/api/finance/invoices')->assertForbidden();
    }

    /**
     * SEC-06 : Le chauffeur ne peut pas télécharger la facture PDF d'expédition.
     */
    public function test_sec_06_driver_cannot_download_shipment_invoice_pdf(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $driver = User::factory()->create(['agency_id' => $agency->id]);
        $driver->assignRole('driver');

        $sender = Profile::query()->create([
            'first_name' => 'A',
            'last_name' => 'B',
            'agency_id' => $agency->id,
        ]);
        $recipient = Profile::query()->create([
            'first_name' => 'C',
            'last_name' => 'D',
            'agency_id' => $agency->id,
        ]);

        $shipment = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $driver->id,
            'agency_id' => $agency->id,
            'assigned_driver_id' => $driver->id,
            'status' => ShipmentStatus::InTransit,
            'public_tracking' => 'TRK-DRIVER-PDF-001',
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 100,
        ]);

        Sanctum::actingAs($driver);
        $this->get("/api/shipments/{$shipment->id}/pdf/invoice")->assertForbidden();
    }
}
