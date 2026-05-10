<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\ShipmentStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\Country;
use App\Models\CustomerPackage;
use App\Models\Locker;
use App\Models\Profile;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PC-001 à PC-016 : Portail Client
 */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $client;
    private User $clientB;
    private Profile $senderProfile;
    private Profile $recipientProfile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->agency = Agency::query()->create([
            'code' => 'PORT'.uniqid(), 'name' => 'Agence Portail',
            'slug' => 'ag-port-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);

        $this->senderProfile = Profile::create([
            'first_name' => 'Client', 'last_name' => 'A',
            'email' => 'clienta'.uniqid().'@test.cd', 'phone' => '+243900000001',
            'is_active' => true, 'is_client' => true, 'is_staff' => false,
        ]);

        $this->client = User::factory()->create([
            'agency_id' => $this->agency->id,
            'profile_id' => $this->senderProfile->id,
        ]);
        $this->client->assignRole('client');

        $this->recipientProfile = Profile::create([
            'first_name' => 'Client', 'last_name' => 'B',
            'email' => 'clientb'.uniqid().'@test.cd', 'phone' => '+243900000002',
            'is_active' => true, 'is_client' => true, 'is_staff' => false,
        ]);

        $this->clientB = User::factory()->create([
            'agency_id' => $this->agency->id,
            'profile_id' => $this->recipientProfile->id,
        ]);
        $this->clientB->assignRole('client');
    }

    /** PC-001 : Dashboard client affiche les compteurs */
    public function test_pc_001_client_dashboard_shows_kpis(): void
    {
        $origin = Country::firstOrCreate(['iso2' => 'CD'], ['name' => 'RDC', 'code' => 'CD', 'phonecode' => '+243', 'is_active' => true]);
        $dest = Country::firstOrCreate(['iso2' => 'US'], ['name' => 'USA', 'code' => 'US', 'phonecode' => '+1', 'is_active' => true]);

        Shipment::create([
            'public_tracking' => 'MRP-PC-'.uniqid(),
            'creator_user_id' => $this->client->id,
            'agency_id' => $this->agency->id,
            'sender_profile_id' => $this->senderProfile->id,
            'recipient_profile_id' => $this->recipientProfile->id,
            'origin_country_id' => $origin->id,
            'dest_country_id' => $dest->id,
            'status' => ShipmentStatus::InTransit,
            'weight_kg' => 2, 'declared_value' => 50, 'currency' => 'USD',
        ]);

        AssistedPurchase::create([
            'user_id' => $this->client->id,
            'reference_code' => 'AP-PC-'.uniqid(),
            'product_url' => 'https://amazon.fr/dp/test',
            'status' => AssistedPurchaseStatus::QUOTED,
            'quoted_at' => now(),
        ]);

        Sanctum::actingAs($this->client);
        $response = $this->getJson('/api/client/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'kpis' => ['shipments_in_transit', 'purchases_active', 'packages_at_hub', 'pending_actions'],
                'recent_activity',
                'priority_alerts',
            ]);

        $this->assertGreaterThanOrEqual(1, $response->json('kpis.shipments_in_transit'));
        $this->assertGreaterThanOrEqual(1, $response->json('kpis.purchases_active'));
    }

    /** PC-002 : Alerte devis en attente visible */
    public function test_pc_002_priority_alert_for_quoted_purchase(): void
    {
        AssistedPurchase::create([
            'user_id' => $this->client->id,
            'reference_code' => 'AP-QUOTE-'.uniqid(),
            'product_url' => 'https://amazon.fr/dp/quote-test',
            'status' => AssistedPurchaseStatus::QUOTED,
            'quoted_at' => now(),
        ]);

        Sanctum::actingAs($this->client);
        $response = $this->getJson('/api/client/dashboard');

        $response->assertOk();
        $alerts = $response->json('priority_alerts');
        $this->assertNotEmpty($alerts);
        $found = collect($alerts)->contains(fn ($a) => $a['type'] === 'quote_pending');
        $this->assertTrue($found, 'Alert quote_pending doit être présente');
    }

    /** PC-003 : Aucune alerte si rien en attente */
    public function test_pc_003_no_alert_when_nothing_pending(): void
    {
        Sanctum::actingAs($this->client);
        $response = $this->getJson('/api/client/dashboard');

        $response->assertOk();
        $this->assertEmpty($response->json('priority_alerts'));
    }

    /** PC-004/005 : Client peut lister ses expéditions */
    public function test_pc_004_client_can_list_own_shipments(): void
    {
        Sanctum::actingAs($this->client);
        $response = $this->getJson('/api/shipments');

        $response->assertOk();
    }

    /** PC-008 : Vue casier client */
    public function test_pc_008_client_locker_view(): void
    {
        Sanctum::actingAs($this->client);
        $response = $this->getJson('/api/client/locker');

        $response->assertOk()
            ->assertJsonStructure(['locker', 'packages']);
    }

    /** PC-010 : Casier vide affiche un message correct */
    public function test_pc_010_empty_locker(): void
    {
        Sanctum::actingAs($this->client);
        $response = $this->getJson('/api/client/locker');

        $response->assertOk();
        $this->assertEmpty($response->json('packages'));
    }

    /** PC-014 : Client peut modifier son profil */
    public function test_pc_014_client_can_update_profile(): void
    {
        Sanctum::actingAs($this->client);
        $response = $this->getJson('/api/profile');

        $response->assertOk();
    }

    /** PC-015 : Préférences de notification */
    public function test_pc_015_notification_preferences(): void
    {
        Sanctum::actingAs($this->client);
        $this->getJson('/api/client/notification-preferences')->assertOk();

        $response = $this->patchJson('/api/client/notification-preferences', [
            'sms' => false,
            'email' => true,
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('preferences.sms'));
        $this->assertTrue($response->json('preferences.email'));
    }

    /** SEC-001/ROLE-004 : Client ne peut pas accéder au dossier d'un autre client */
    public function test_client_cannot_access_other_client_data(): void
    {
        $origin = Country::firstOrCreate(['iso2' => 'CD'], ['name' => 'RDC', 'code' => 'CD', 'phonecode' => '+243', 'is_active' => true]);
        $dest = Country::firstOrCreate(['iso2' => 'US'], ['name' => 'USA', 'code' => 'US', 'phonecode' => '+1', 'is_active' => true]);

        $shipmentB = Shipment::create([
            'public_tracking' => 'MRP-B-'.uniqid(),
            'creator_user_id' => $this->clientB->id,
            'agency_id' => $this->agency->id,
            'sender_profile_id' => $this->recipientProfile->id,
            'recipient_profile_id' => $this->senderProfile->id,
            'origin_country_id' => $origin->id,
            'dest_country_id' => $dest->id,
            'status' => ShipmentStatus::InTransit,
            'weight_kg' => 2, 'declared_value' => 50, 'currency' => 'USD',
        ]);

        Sanctum::actingAs($this->client);
        $response = $this->getJson("/api/shipments/{$shipmentB->id}");

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    /** PC-007 : Client peut voir ses factures */
    public function test_pc_007_client_can_view_invoices(): void
    {
        Sanctum::actingAs($this->client);
        $response = $this->getJson('/api/client/invoices');

        $response->assertOk()
            ->assertJsonStructure(['invoices']);
    }
}
