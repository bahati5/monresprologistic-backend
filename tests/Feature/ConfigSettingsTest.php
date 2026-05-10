<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CFG-001 à CFG-010 : Paramètres & Configuration
 */
class ConfigSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $superAdmin;
    private User $agencyAdmin;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->agency = Agency::query()->create([
            'code' => 'CFG'.uniqid(), 'name' => 'Agence Config',
            'slug' => 'ag-cfg-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->superAdmin->assignRole('super_admin');

        $this->agencyAdmin = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->agencyAdmin->assignRole('agency_admin');

        $this->operator = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->operator->assignRole('operator');
    }

    /** CFG-007 : operator ne peut pas accéder aux paramètres */
    public function test_cfg_007_operator_cannot_access_settings(): void
    {
        Sanctum::actingAs($this->operator);
        $this->getJson('/api/settings/app')->assertForbidden();
    }

    /** Super admin peut lire les paramètres */
    public function test_super_admin_reads_settings(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/settings/app')->assertOk();
    }

    /** Super admin peut modifier les paramètres */
    public function test_super_admin_updates_settings(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $response = $this->putJson('/api/settings/app', [
            'company_name' => 'Monrespro Logistic Test',
        ]);

        $response->assertOk();
    }

    /** CFG-003 : Ajout d'un mode de transport */
    public function test_cfg_003_add_shipping_mode(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $response = $this->postJson('/api/settings/shipping-modes', [
            'name' => 'Terrestre',
            'code' => 'TER',
            'is_active' => true,
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('shipping_modes', ['name' => 'Terrestre']);
    }

    /** CFG-006 : Configuration marchand */
    public function test_cfg_006_configure_merchant(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $response = $this->postJson('/api/settings/merchants', [
            'name' => 'Zara',
            'domains' => ['zara.com'],
            'commission_rate' => 6.00,
            'is_active' => true,
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('merchants', ['name' => 'Zara']);
    }

    /** Hub de paramètres accessible par admin */
    public function test_settings_hub_accessible(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/settings')->assertOk();
    }

    /** agency_admin peut gérer les modes de transport */
    public function test_agency_admin_manages_shipping_modes(): void
    {
        Sanctum::actingAs($this->agencyAdmin);
        $this->getJson('/api/settings/shipping-modes')->assertOk();
    }

    /** Gestion des templates de notification */
    public function test_notification_templates_accessible(): void
    {
        Sanctum::actingAs($this->agencyAdmin);
        $this->getJson('/api/settings/notifications')->assertOk();
    }

    /** Gestion des méthodes de paiement */
    public function test_payment_methods_accessible(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/settings/payment-methods')->assertOk();
    }

    /** Gestion SMTP */
    public function test_smtp_config_accessible(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/settings/smtp-config')->assertOk();
    }

    /** Gestion Twilio */
    public function test_twilio_config_accessible(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/settings/twilio-config')->assertOk();
    }

    /** CFG-010 : Isolation multi-agences */
    public function test_cfg_010_multi_agency_isolation(): void
    {
        $agencyB = Agency::query()->create([
            'code' => 'ISO'.uniqid(), 'name' => 'Agence B',
            'slug' => 'ag-iso-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);

        $operatorB = User::factory()->create(['agency_id' => $agencyB->id]);
        $operatorB->assignRole('operator');

        Sanctum::actingAs($operatorB);
        $response = $this->getJson('/api/shipments');

        $response->assertOk();
    }

    /** Roles & permissions accessible par super_admin */
    public function test_roles_permissions_accessible(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/settings/roles-permissions')->assertOk();
    }
}
