<?php

namespace Tests\Feature;

use App\Enums\ShipmentStatus;
use App\Models\Agency;
use App\Models\Profile;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArchiveSignedFormTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'TAF'.uniqid(),
            'name' => 'Agence test',
            'slug' => 'ag-af-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: Shipment, 2: Profile, 3: Profile}
     */
    private function operatorWithShipment(): array
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

        $shipment = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $operator->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::ReceivedAtHub,
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 10,
        ]);

        return [$operator, $shipment, $sender, $recipient];
    }

    public function test_operator_can_archive_signed_form_and_receives_url(): void
    {
        Storage::fake('public');
        [$operator, $shipment] = $this->operatorWithShipment();

        Sanctum::actingAs($operator);

        $file = UploadedFile::fake()->create('formulaire.pdf', 200, 'application/pdf');

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/shipments/{$shipment->id}/archive-signed-form", [
                'signed_form' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Formulaire signé archivé.')
            ->assertJsonStructure(['signed_form_url']);

        $shipment->refresh();
        $this->assertNotNull($shipment->signed_form_path);
        Storage::disk('public')->assertExists($shipment->signed_form_path);

        $this->assertDatabaseHas('shipment_logs', [
            'shipment_id' => $shipment->id,
            'user_id' => $operator->id,
            'title' => 'Formulaire signé archivé',
        ]);
    }

    public function test_second_upload_replaces_previous_file(): void
    {
        Storage::fake('public');
        [$operator, $shipment] = $this->operatorWithShipment();
        Sanctum::actingAs($operator);

        $first = UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf');
        $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/shipments/{$shipment->id}/archive-signed-form", [
                'signed_form' => $first,
            ])->assertOk();

        $shipment->refresh();
        $firstPath = $shipment->signed_form_path;
        $this->assertNotNull($firstPath);
        Storage::disk('public')->assertExists($firstPath);

        $second = UploadedFile::fake()->create('v2.pdf', 120, 'application/pdf');
        $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/shipments/{$shipment->id}/archive-signed-form", [
                'signed_form' => $second,
            ])->assertOk();

        $shipment->refresh();
        $this->assertNotSame($firstPath, $shipment->signed_form_path);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($shipment->signed_form_path);
    }

    public function test_invalid_mime_returns_validation_error(): void
    {
        Storage::fake('public');
        [$operator, $shipment] = $this->operatorWithShipment();
        Sanctum::actingAs($operator);

        $bad = UploadedFile::fake()->create('note.txt', 10, 'text/plain');

        $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/shipments/{$shipment->id}/archive-signed-form", [
                'signed_form' => $bad,
            ])->assertUnprocessable();
    }

    public function test_client_cannot_archive_signed_form(): void
    {
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        $sender = Profile::query()->create(['first_name' => 'A', 'last_name' => 'B', 'agency_id' => $agency->id]);
        $recipient = Profile::query()->create(['first_name' => 'C', 'last_name' => 'D', 'agency_id' => $agency->id]);

        $shipment = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $operator->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::Draft,
            'declared_currency' => 'USD',
            'currency' => 'USD',
        ]);

        Sanctum::actingAs($client);

        $file = UploadedFile::fake()->create('formulaire.pdf', 80, 'application/pdf');

        $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/shipments/{$shipment->id}/archive-signed-form", [
                'signed_form' => $file,
            ])->assertForbidden();
    }
}
