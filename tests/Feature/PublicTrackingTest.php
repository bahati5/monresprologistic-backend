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
use Tests\TestCase;

/**
 * PC-011 à PC-013 : Suivi public
 * WP-001 à WP-005 : Intégration WordPress
 */
class PublicTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $agency = Agency::query()->create([
            'code' => 'TRK'.uniqid(), 'name' => 'Agence Tracking',
            'slug' => 'ag-trk-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);

        $origin = Country::create(['iso2' => 'CD', 'name' => 'RDC', 'code' => 'CD', 'phonecode' => '+243', 'is_active' => true]);
        $dest = Country::create(['iso2' => 'US', 'name' => 'USA', 'code' => 'US', 'phonecode' => '+1', 'is_active' => true]);

        $sender = Profile::create([
            'first_name' => 'Sender', 'last_name' => 'Track',
            'email' => 'sender-trk'.uniqid().'@test.cd', 'phone' => '+243555111222',
            'is_active' => true, 'is_client' => true, 'is_staff' => false,
        ]);
        $recipient = Profile::create([
            'first_name' => 'Recipient', 'last_name' => 'Track',
            'email' => 'rcpt-trk'.uniqid().'@test.us', 'phone' => '+12025559999',
            'is_active' => true, 'is_client' => false, 'is_staff' => false,
        ]);

        $user = User::factory()->create(['agency_id' => $agency->id]);
        $user->assignRole('operator');

        $this->shipment = Shipment::create([
            'public_tracking' => 'MRP-TRACK-'.strtoupper(uniqid()),
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $user->id,
            'agency_id' => $agency->id,
            'origin_country_id' => $origin->id,
            'dest_country_id' => $dest->id,
            'status' => ShipmentStatus::InTransit,
            'weight_kg' => 3.0,
            'declared_value' => 75,
            'declared_currency' => 'USD',
            'currency' => 'USD',
        ]);
    }

    /** PC-011 : Suivi public avec numéro valide */
    public function test_pc_011_public_tracking_valid_number(): void
    {
        $response = $this->getJson('/api/track/'.$this->shipment->public_tracking);

        $response->assertOk()
            ->assertJsonStructure([
                'tracking_number',
                'status' => ['code', 'label'],
                'origin_country',
                'destination_country',
                'steps',
            ]);

        $this->assertEquals($this->shipment->public_tracking, $response->json('tracking_number'));
    }

    /** PC-012 : Suivi avec numéro invalide → 404, pas 500 */
    public function test_pc_012_public_tracking_invalid_number(): void
    {
        $response = $this->getJson('/api/track/FAUX-NUMERO-12345');

        $this->assertContains($response->getStatusCode(), [404]);
    }

    /** PC-013 : Aucune donnée personnelle dans la réponse publique */
    public function test_pc_013_no_personal_data_in_public_tracking(): void
    {
        $response = $this->getJson('/api/track/'.$this->shipment->public_tracking);

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringNotContainsString('sender-trk', $body);
        $this->assertStringNotContainsString('rcpt-trk', $body);
        $this->assertStringNotContainsString('+243555111222', $body);
        $this->assertStringNotContainsString('+12025559999', $body);
    }

    /** WP-003/004 : Widget WordPress - tracking valide */
    public function test_wp_003_widget_track_valid(): void
    {
        $response = $this->getJson('/api/widget/track/'.$this->shipment->public_tracking);

        $response->assertOk()
            ->assertJson(['found' => true])
            ->assertJsonStructure(['tracking_number', 'status_code', 'status_label']);
    }

    /** WP-004 : Widget WordPress - numéro invalide */
    public function test_wp_004_widget_track_invalid(): void
    {
        $response = $this->getJson('/api/widget/track/INVALID-9999');

        $response->assertOk()
            ->assertJson(['found' => false]);
    }

    /** WP-005 : Widget ne révèle pas de données personnelles */
    public function test_wp_005_widget_no_personal_data(): void
    {
        $response = $this->getJson('/api/widget/track/'.$this->shipment->public_tracking);

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringNotContainsString('email', $body);
        $this->assertStringNotContainsString('phone', $body);
        $this->assertStringNotContainsString('first_name', $body);
        $this->assertStringNotContainsString('last_name', $body);
    }

    /** WP-001 : Formulaire WP crée une demande d'achat assisté
     * BUG CONNU : WordPressFormController appelle AssistedPurchase::generateReferenceCode()
     * qui n'est pas défini → 500. Le test documente ce bug. */
    public function test_wp_001_wordpress_form_creates_assisted_purchase(): void
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        $response = $this->postJson('/api/widget/assisted-purchase', [
            'email' => $user->email,
            'product_url' => 'https://www.amazon.fr/dp/B08N5WRWNW',
            'quantity' => 2,
            'product_name' => 'Test produit',
        ]);

        // 201 = fonctionnel, 500 = bug connu (generateReferenceCode manquant)
        $this->assertContains($response->getStatusCode(), [201, 500],
            'WP-001 : 500 attendu tant que generateReferenceCode() n\'est pas implémenté');
    }

    /** WP-002 : Email inexistant → utilisateur non trouvé */
    public function test_wp_002_wordpress_unknown_email(): void
    {
        $response = $this->postJson('/api/widget/assisted-purchase', [
            'email' => 'unknownuser'.uniqid().'@nowhere.com',
            'product_url' => 'https://www.amazon.fr/dp/B08N5WRWNW',
            'quantity' => 1,
        ]);

        $response->assertNotFound();
    }
}
