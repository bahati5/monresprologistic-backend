<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * NOTIF-001 à NOTIF-008 : Notifications multi-canaux
 */
class NotificationAuditTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $superAdmin;
    private User $operator;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->agency = Agency::query()->create([
            'code' => 'NOT'.uniqid(), 'name' => 'Agence Notif',
            'slug' => 'ag-not-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->superAdmin->assignRole('super_admin');

        $this->operator = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->operator->assignRole('operator');

        $this->client = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->client->assignRole('client');
    }

    /** NOTIF-003 : Notifications in-app listées */
    public function test_notif_003_list_notifications(): void
    {
        Sanctum::actingAs($this->operator);
        $this->getJson('/api/notifications')->assertOk();
    }

    /** NOTIF-003 : Compteur non-lu */
    public function test_notif_003_unread_count(): void
    {
        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/notifications/unread-count');
        $response->assertOk();
    }

    /** Marquer toutes les notifications comme lues */
    public function test_mark_all_read(): void
    {
        Sanctum::actingAs($this->operator);
        $this->postJson('/api/notifications/read-all')->assertOk();
    }

    /** NOTIF-005 : Log de notification (audit) */
    public function test_notif_005_notification_audit_log(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $response = $this->getJson('/api/notifications/audit-log');
        $response->assertOk();
    }

    /** Client peut voir ses notifications */
    public function test_client_can_view_notifications(): void
    {
        Sanctum::actingAs($this->client);
        $this->getJson('/api/notifications')->assertOk();
    }

    /** Branding public accessible sans auth */
    public function test_branding_public(): void
    {
        $this->getJson('/api/branding')->assertOk();
    }
}
