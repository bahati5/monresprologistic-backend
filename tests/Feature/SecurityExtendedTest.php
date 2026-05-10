<?php

namespace Tests\Feature;

use App\Enums\ShipmentStatus;
use App\Models\Agency;
use App\Models\Country;
use App\Models\Profile;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SEC-001 à SEC-012 : Tests de sécurité & permissions étendus
 */
class SecurityExtendedTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agencyA;
    private Agency $agencyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->agencyA = Agency::query()->create([
            'code' => 'SECA'.uniqid(), 'name' => 'Agence A',
            'slug' => 'ag-seca-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);
        $this->agencyB = Agency::query()->create([
            'code' => 'SECB'.uniqid(), 'name' => 'Agence B',
            'slug' => 'ag-secb-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);
    }

    private function userWithRole(string $role, ?Agency $agency = null): User
    {
        $user = User::factory()->create(['agency_id' => ($agency ?? $this->agencyA)->id]);
        $user->assignRole($role);
        return $user;
    }

    /** SEC-004 : Accès sans authentification → 401 */
    public function test_sec_004_unauthenticated_access_returns_401(): void
    {
        $this->getJson('/api/shipments')->assertUnauthorized();
        $this->getJson('/api/refunds')->assertUnauthorized();
        $this->getJson('/api/users')->assertUnauthorized();
        $this->getJson('/api/finance/ledger')->assertUnauthorized();
        $this->getJson('/api/client/dashboard')->assertUnauthorized();
    }

    /** SEC-003 : Élévation de privilège — operator ne peut pas approuver */
    public function test_sec_003_operator_cannot_call_approve_endpoint(): void
    {
        $operator = $this->userWithRole('operator');
        Sanctum::actingAs($operator);

        $response = $this->postJson('/api/refunds/999/approve');
        // 403 (permission denied) ou 404 (route model binding, refund non trouvé)
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    /** SEC-006 : Injection SQL via recherche → pas d'erreur 500 */
    public function test_sec_006_sql_injection_search_safe(): void
    {
        $operator = $this->userWithRole('operator');
        Sanctum::actingAs($operator);

        $response = $this->getJson('/api/shipments?search='.urlencode("'; DROP TABLE shipments;--"));

        $this->assertNotEquals(500, $response->getStatusCode());
    }

    /** SEC-007 : XSS via champ description — chaîne stockée sans exécution */
    public function test_sec_007_xss_in_description_safely_stored(): void
    {
        $client = $this->userWithRole('client');
        Sanctum::actingAs($client);

        $xssPayload = '<script>alert("xss")</script>';
        $response = $this->postJson('/api/assisted-purchases', [
            'product_url' => 'https://example.com/product',
            'notes' => $xssPayload,
        ]);

        // La requête ne devrait pas planter (200, 201 ou 422 pour validation, jamais 500)
        $this->assertNotEquals(500, $response->getStatusCode());
    }

    /** SEC-008 : Upload fichier malveillant (.php) bloqué */
    public function test_sec_008_malicious_file_upload_rejected(): void
    {
        $operator = $this->userWithRole('operator');
        Sanctum::actingAs($operator);

        $origin = Country::firstOrCreate(['iso2' => 'CD'], ['name' => 'RDC', 'code' => 'CD', 'phonecode' => '+243', 'is_active' => true]);
        $dest = Country::firstOrCreate(['iso2' => 'US'], ['name' => 'USA', 'code' => 'US', 'phonecode' => '+1', 'is_active' => true]);
        $sender = Profile::create(['first_name' => 'S', 'last_name' => 'T', 'email' => 's'.uniqid().'@t.cd', 'phone' => '+243111', 'is_active' => true, 'is_client' => true, 'is_staff' => false]);
        $recipient = Profile::create(['first_name' => 'R', 'last_name' => 'T', 'email' => 'r'.uniqid().'@t.cd', 'phone' => '+1222', 'is_active' => true, 'is_client' => false, 'is_staff' => false]);

        $shipment = Shipment::create([
            'public_tracking' => 'MRP-SEC-'.uniqid(),
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $operator->id,
            'agency_id' => $this->agencyA->id,
            'origin_country_id' => $origin->id,
            'dest_country_id' => $dest->id,
            'status' => ShipmentStatus::ReceivedAtHub,
            'weight_kg' => 2, 'declared_value' => 50, 'currency' => 'USD',
        ]);

        $phpFile = \Illuminate\Http\UploadedFile::fake()->create('malicious.php', 100, 'application/x-php');

        $response = $this->postJson("/api/shipments/{$shipment->id}/archive-signed-form", [
            'signed_form' => $phpFile,
        ]);

        $response->assertUnprocessable();
    }

    /** SEC-002 : IDOR entre agences — utilisateur agence A ne peut pas voir données agence B */
    public function test_sec_002_idor_between_agencies(): void
    {
        $operatorA = $this->userWithRole('operator', $this->agencyA);
        $operatorB = $this->userWithRole('operator', $this->agencyB);

        $origin = Country::firstOrCreate(['iso2' => 'CD'], ['name' => 'RDC', 'code' => 'CD', 'phonecode' => '+243', 'is_active' => true]);
        $dest = Country::firstOrCreate(['iso2' => 'US'], ['name' => 'USA', 'code' => 'US', 'phonecode' => '+1', 'is_active' => true]);
        $sender = Profile::create(['first_name' => 'AX', 'last_name' => 'B', 'email' => 'ax'.uniqid().'@test.cd', 'phone' => '+243888', 'is_active' => true, 'is_client' => true, 'is_staff' => false]);
        $recipient = Profile::create(['first_name' => 'BX', 'last_name' => 'B', 'email' => 'bx'.uniqid().'@test.us', 'phone' => '+1333', 'is_active' => true, 'is_client' => false, 'is_staff' => false]);

        $shipmentB = Shipment::create([
            'public_tracking' => 'MRP-IDOR-'.uniqid(),
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $operatorB->id,
            'agency_id' => $this->agencyB->id,
            'origin_country_id' => $origin->id,
            'dest_country_id' => $dest->id,
            'status' => ShipmentStatus::InTransit,
            'weight_kg' => 3, 'declared_value' => 100, 'currency' => 'USD',
        ]);

        Sanctum::actingAs($operatorA);
        $response = $this->getJson("/api/shipments/{$shipmentB->id}");

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    /** Driver ne peut pas créer des expéditions */
    public function test_driver_cannot_create_shipments(): void
    {
        Sanctum::actingAs($this->userWithRole('driver'));
        $this->postJson('/api/shipments', ['test' => 'data'])->assertForbidden();
    }

    /** Client ne peut pas gérer les utilisateurs */
    public function test_client_cannot_manage_users(): void
    {
        Sanctum::actingAs($this->userWithRole('client'));
        $this->getJson('/api/users')->assertForbidden();
    }

    /** Operator ne peut pas accéder au dashboard analytics */
    public function test_operator_cannot_access_analytics(): void
    {
        Sanctum::actingAs($this->userWithRole('operator'));
        $this->getJson('/api/dashboard/analytics')->assertForbidden();
    }
}
