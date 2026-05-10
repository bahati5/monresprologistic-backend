<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ROLE-001 à ROLE-010 : Matrice des accès par rôle
 */
class RoleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->agency = Agency::query()->create([
            'code' => 'ROLE'.uniqid(), 'name' => 'Agence test ROLE',
            'slug' => 'ag-role-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $user->assignRole($role);
        return $user;
    }

    /** ROLE-001 : operator ne peut pas accéder aux paramètres système */
    public function test_role_001_operator_cannot_access_system_settings(): void
    {
        Sanctum::actingAs($this->userWithRole('operator'));
        $this->getJson('/api/settings/app')->assertForbidden();
    }

    /** ROLE-002 : operator ne peut pas approuver remboursement > seuil */
    public function test_role_002_operator_cannot_approve_large_refund(): void
    {
        $operator = $this->userWithRole('operator');
        $this->assertFalse($operator->can('approve_refunds'));
    }

    /** ROLE-003 : driver ne voit que ses tâches assignées */
    public function test_role_003_driver_sees_only_assigned_tasks(): void
    {
        $driver = $this->userWithRole('driver');
        $this->assertFalse($driver->can('create_shipments'));
        $this->assertFalse($driver->can('manage_settings'));
        $this->assertTrue($driver->can('manage_pickups'));
    }

    /** ROLE-005 : client ne peut pas créer d'expédition */
    public function test_role_005_client_cannot_create_shipment_directly(): void
    {
        Sanctum::actingAs($this->userWithRole('client'));
        $this->postJson('/api/shipments', ['test' => true])
            ->assertStatus(403);
    }

    /** ROLE-006 : agency_admin peut configurer les agences */
    public function test_role_006_agency_admin_can_manage_agencies(): void
    {
        Sanctum::actingAs($this->userWithRole('agency_admin'));
        $this->getJson('/api/settings/agencies')->assertOk();
    }

    /** ROLE-007 : agency_admin ne peut pas accéder aux paramètres système globaux */
    public function test_role_007_agency_admin_cannot_access_global_settings(): void
    {
        Sanctum::actingAs($this->userWithRole('agency_admin'));
        $this->getJson('/api/settings/app')->assertForbidden();
    }

    /** ROLE-008 : super_admin a accès à tout */
    public function test_role_008_super_admin_has_full_access(): void
    {
        $sa = $this->userWithRole('super_admin');
        Sanctum::actingAs($sa);

        $this->getJson('/api/settings/app')->assertOk();
        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/finance/ledger')->assertOk();
    }

    /** ROLE-009 : operator ne peut pas configurer les taux de change */
    public function test_role_009_operator_cannot_manage_exchange_rates(): void
    {
        Sanctum::actingAs($this->userWithRole('operator'));
        $this->getJson('/api/settings/exchange-rates')->assertForbidden();
    }

    /** ROLE-010 : client ne peut pas voir les regroupements */
    public function test_role_010_client_cannot_access_regroupements(): void
    {
        Sanctum::actingAs($this->userWithRole('client'));
        $this->getJson('/api/regroupements')->assertForbidden();
    }

    /** Operator peut voir la liste des expéditions */
    public function test_operator_can_view_shipments(): void
    {
        Sanctum::actingAs($this->userWithRole('operator'));
        $this->getJson('/api/shipments')->assertOk();
    }

    /** Driver ne peut pas accéder aux finances */
    public function test_driver_cannot_access_finances(): void
    {
        Sanctum::actingAs($this->userWithRole('driver'));
        $this->getJson('/api/finance/ledger')->assertForbidden();
    }

    /** customs_agent peut voir les expéditions */
    public function test_customs_agent_can_view_shipments(): void
    {
        Sanctum::actingAs($this->userWithRole('customs_agent'));
        $this->getJson('/api/shipments')->assertOk();
    }
}
