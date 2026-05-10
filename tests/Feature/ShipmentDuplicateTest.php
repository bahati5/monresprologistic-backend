<?php

namespace Tests\Feature;

use App\Enums\ShipmentStatus;
use App\Models\Agency;
use App\Models\Profile;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'TD'.uniqid(),
            'name' => 'Agence test dup',
            'slug' => 'ag-td-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_operator_duplicates_shipment_as_draft_with_new_tracking(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $sender = Profile::query()->create([
            'first_name' => 'Exp',
            'last_name' => 'Editeur',
            'agency_id' => $agency->id,
        ]);
        $recipient = Profile::query()->create([
            'first_name' => 'Dest',
            'last_name' => 'Inataire',
            'agency_id' => $agency->id,
        ]);

        $source = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $operator->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::Delivered,
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 42,
            'service_options' => [
                'manual_pricing' => true,
                'manual_price_per_kg' => 5.0,
            ],
            'pricing_snapshot' => [
                'insurance_pct' => 0,
                'customs_duty_pct' => 0,
                'tax_pct' => 0,
                'discount_pct' => 0,
                'fixed_fees' => 0,
                'manual_fee' => 0,
            ],
        ]);

        ShipmentItem::query()->create([
            'shipment_id' => $source->id,
            'description' => 'Livres',
            'quantity' => 1,
            'weight_kg' => 2.5,
            'value' => 20,
        ]);

        Sanctum::actingAs($operator);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson("/api/shipments/{$source->id}/duplicate");

        $response->assertCreated()
            ->assertJsonPath('message', 'Expédition dupliquée en brouillon.')
            ->assertJsonStructure(['id', 'public_tracking']);

        $newId = (int) $response->json('id');
        $this->assertNotSame($source->id, $newId);

        $copy = Shipment::query()->with('items')->findOrFail($newId);
        $this->assertSame(ShipmentStatus::Draft, $copy->status);
        $this->assertNotSame($source->public_tracking, $copy->public_tracking);
        $this->assertSame((int) $source->sender_profile_id, (int) $copy->sender_profile_id);
        $this->assertSame((int) $source->recipient_profile_id, (int) $copy->recipient_profile_id);
        $this->assertNull($copy->signed_form_path);
        $this->assertCount(1, $copy->items);
        $this->assertSame('Livres', $copy->items->first()->description);

        $this->assertDatabaseHas('shipment_logs', [
            'shipment_id' => $newId,
            'user_id' => $operator->id,
            'title' => 'Expédition dupliquée',
        ]);
    }

    public function test_client_cannot_duplicate(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        $sender = Profile::query()->create([
            'first_name' => 'C',
            'last_name' => 'Lient',
            'agency_id' => $agency->id,
            'user_id' => $client->id,
        ]);
        $recipient = Profile::query()->create([
            'first_name' => 'R',
            'last_name' => 'ecip',
            'agency_id' => $agency->id,
        ]);

        $shipment = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $client->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::Draft,
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 1,
        ]);
        ShipmentItem::query()->create([
            'shipment_id' => $shipment->id,
            'description' => 'X',
            'quantity' => 1,
            'weight_kg' => 1,
        ]);

        Sanctum::actingAs($client);

        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson("/api/shipments/{$shipment->id}/duplicate")
            ->assertStatus(403);
    }
}
