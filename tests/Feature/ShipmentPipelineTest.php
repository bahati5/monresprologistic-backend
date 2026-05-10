<?php

namespace Tests\Feature;

use App\Enums\ShipmentStatus;
use App\Models\Agency;
use App\Models\Country;
use App\Models\Profile;
use App\Models\Shipment;
use App\Models\ShipmentLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * EXP-013 à EXP-024 : Pipeline de statuts expédition
 */
class ShipmentPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $operator;
    private Profile $senderProfile;
    private Profile $recipientProfile;
    private Country $originCountry;
    private Country $destCountry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->agency = Agency::query()->create([
            'code' => 'PIPE'.uniqid(), 'name' => 'Agence Pipeline',
            'slug' => 'ag-pipe-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);

        $this->operator = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->operator->assignRole('operator');

        $this->originCountry = Country::firstOrCreate(
            ['iso2' => 'CD'],
            ['name' => 'RD Congo', 'code' => 'CD', 'phonecode' => '+243', 'is_active' => true]
        );
        $this->destCountry = Country::firstOrCreate(
            ['iso2' => 'US'],
            ['name' => 'États-Unis', 'code' => 'US', 'phonecode' => '+1', 'is_active' => true]
        );

        $this->senderProfile = Profile::create([
            'first_name' => 'Expéditeur', 'last_name' => 'Test',
            'email' => 'sender'.uniqid().'@test.cd', 'phone' => '+243111111111',
            'is_active' => true, 'is_client' => true, 'is_staff' => false,
        ]);
        $this->recipientProfile = Profile::create([
            'first_name' => 'Destinataire', 'last_name' => 'Test',
            'email' => 'rcpt'.uniqid().'@test.us', 'phone' => '+12025551234',
            'is_active' => true, 'is_client' => false, 'is_staff' => false,
        ]);
    }

    private function createShipment(ShipmentStatus $status = ShipmentStatus::ReceivedAtHub): Shipment
    {
        return Shipment::create([
            'public_tracking' => 'MRP-TEST-'.strtoupper(uniqid()),
            'sender_profile_id' => $this->senderProfile->id,
            'recipient_profile_id' => $this->recipientProfile->id,
            'creator_user_id' => $this->operator->id,
            'agency_id' => $this->agency->id,
            'origin_country_id' => $this->originCountry->id,
            'dest_country_id' => $this->destCountry->id,
            'status' => $status,
            'weight_kg' => 5.0,
            'declared_value' => 100,
            'declared_currency' => 'USD',
            'currency' => 'USD',
        ]);
    }

    /** EXP-015 : Transition RECEIVED_AT_HUB → READY_FOR_DISPATCH (nécessite paiement) */
    public function test_exp_015_transition_to_ready_for_dispatch(): void
    {
        Sanctum::actingAs($this->operator);
        $shipment = $this->createShipment(ShipmentStatus::ReceivedAtHub);
        $shipment->update(['payment_status' => 'paid', 'amount_paid' => 100, 'paid_at' => now()]);

        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => 'ready_for_dispatch',
        ]);

        $response->assertSuccessful();
        $this->assertEquals('ready_for_dispatch', $shipment->fresh()->status->value);
    }

    /** EXP-015b : Transition RECEIVED_AT_HUB → READY_FOR_DISPATCH sans paiement → 422 */
    public function test_exp_015b_ready_for_dispatch_without_payment_blocked(): void
    {
        Sanctum::actingAs($this->operator);
        $shipment = $this->createShipment(ShipmentStatus::ReceivedAtHub);

        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => 'ready_for_dispatch',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    /** EXP-018 : Transition READY_FOR_DISPATCH → IN_TRANSIT */
    public function test_exp_018_transition_to_in_transit(): void
    {
        Sanctum::actingAs($this->operator);
        $shipment = $this->createShipment(ShipmentStatus::ReadyForDispatch);

        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => 'in_transit',
        ]);

        $response->assertSuccessful();
        $this->assertEquals('in_transit', $shipment->fresh()->status->value);
    }

    /** EXP-019 : Transition IN_TRANSIT → ARRIVED_AT_DESTINATION */
    public function test_exp_019_transition_to_arrived_at_destination(): void
    {
        Sanctum::actingAs($this->operator);
        $shipment = $this->createShipment(ShipmentStatus::InTransit);

        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => 'arrived_at_destination',
        ]);

        $response->assertSuccessful();
        $this->assertEquals('arrived_at_destination', $shipment->fresh()->status->value);
    }

    /** EXP-021 : Blocage douane */
    public function test_exp_021_customs_hold(): void
    {
        Sanctum::actingAs($this->operator);
        $shipment = $this->createShipment(ShipmentStatus::InTransit);

        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => 'customs_hold',
        ]);

        $response->assertSuccessful();
        $this->assertEquals('customs_hold', $shipment->fresh()->status->value);
    }

    /** EXP-022 : Annulation avant départ */
    public function test_exp_022_cancellation_before_dispatch(): void
    {
        Sanctum::actingAs($this->operator);
        $shipment = $this->createShipment(ShipmentStatus::ReceivedAtHub);

        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => 'cancelled',
        ]);

        $response->assertSuccessful();
        $this->assertEquals('cancelled', $shipment->fresh()->status->value);
    }

    /** EXP-024 : Transition illicite DRAFT → DELIVERED bloquée */
    public function test_exp_024_illegal_transition_blocked(): void
    {
        Sanctum::actingAs($this->operator);
        $shipment = $this->createShipment(ShipmentStatus::Draft);

        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => 'delivered',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('draft', $shipment->fresh()->status->value);
    }

    /** Transition RECEIVED_AT_HUB → DELIVERED (saut interdit) */
    public function test_skip_transition_received_to_delivered_blocked(): void
    {
        Sanctum::actingAs($this->operator);
        $shipment = $this->createShipment(ShipmentStatus::ReceivedAtHub);

        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => 'delivered',
        ]);

        $response->assertStatus(422);
    }

    /** EXP-002 : Numéro de suivi unique à la création */
    public function test_exp_002_unique_tracking_number(): void
    {
        $s1 = $this->createShipment();
        $s2 = $this->createShipment();

        $this->assertNotEquals($s1->public_tracking, $s2->public_tracking);
    }

    /** Le statut Delivered est terminal (aucune transition possible) */
    public function test_delivered_is_terminal(): void
    {
        $this->assertEmpty(ShipmentStatus::Delivered->allowedNext());
    }

    /** Le statut Cancelled est terminal */
    public function test_cancelled_is_terminal(): void
    {
        $this->assertEmpty(ShipmentStatus::Cancelled->allowedNext());
    }
}
