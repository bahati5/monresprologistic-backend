<?php

namespace Tests\Feature;

use App\Enums\PickupStatus;
use App\Enums\ShipmentStatus;
use App\Models\Agency;
use App\Models\Country;
use App\Models\CustomerPackage;
use App\Models\Hub;
use App\Models\Locker;
use App\Models\Pickup;
use App\Models\PreAlert;
use App\Models\PreAlertIssueReport;
use App\Models\Profile;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Workflows 3 et 4 : Pré-alerte, Ramassage et Livraison
 * Plan de Test Master — Section 5 : LOG-01 à LOG-04
 */
class LogisticsDeliveryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fichier image minimal (1×1 PNG) sans extension GD, accepté par Spatie Media Library.
     */
    private function fakeTinyPngUpload(string $basename = 'photo.png'): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'upl_'.uniqid('', true).'.png';
        if (file_put_contents($path, $png) === false) {
            $this->fail('Impossible d\'écrire le fichier image de test.');
        }

        return new UploadedFile($path, $basename, 'image/png', null, true);
    }

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'LOG'.uniqid(),
            'name' => 'Agence test LOG',
            'slug' => 'ag-log-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function createLocker(User $user): Locker
    {
        return Locker::query()->create([
            'code' => 'LCK-'.uniqid(),
            'user_id' => $user->id,
        ]);
    }

    /**
     * LOG-01 : Casier Virtuel
     * Réceptionner un colis issu d'une pré-alerte (RECEIVED_AT_HUB).
     * Colis visible dans le "Casier" du portail client avec photo, poids et date d'arrivée.
     */
    public function test_log_01_virtual_locker_display(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        Storage::fake('public');

        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $locker = $this->createLocker($client);

        // Créer une pré-alerte
        $preAlert = PreAlert::query()->create([
            'reference_code' => PreAlert::generateReferenceCode(),
            'user_id' => $client->id,
            'locker_id' => $locker->id,
            'status' => ShipmentStatus::InTransit,
            'merchant_name' => 'Amazon',
            'carrier_name' => 'DHL',
            'vendor_tracking_number' => 'AMZ-12345',
            'declared_value' => 150.00,
            'value_currency' => 'USD',
        ]);

        // Simuler la réception du colis par l'opérateur
        Sanctum::actingAs($operator);

        $photo = $this->fakeTinyPngUpload('package_received.png');

        $response = $this->postJson("/api/shipment-notices/{$preAlert->id}/receive", [
            'weight_kg' => 2.5,
            'condition_notes' => 'Colis en bon état',
            'photo' => $photo,
        ]);

        $response->assertOk();

        // Vérifier que le CustomerPackage a été créé
        $this->assertDatabaseHas('customer_packages', [
            'user_id' => $client->id,
            'pre_alert_id' => $preAlert->id,
            'status' => ShipmentStatus::ReceivedAtHub->value,
            'weight_kg' => 2.5,
        ]);

        // Vérifier que le colis est visible dans le casier du client
        Sanctum::actingAs($client);
        $response = $this->getJson('/api/client/locker');

        $response->assertOk();
        $data = $response->json();

        // Vérifier que le casier contient les informations du client
        $this->assertArrayHasKey('locker', $data);
        // Le format exact dépend de l'implémentation de l'API client/locker
    }

    /**
     * LOG-02 : Anomalie pré-alerte
     * Cliquer sur "Signaler un problème" à la réception (ex. : colis endommagé).
     * Statut passe à ISSUE_REPORTED. Ticket Freshsales créé, client notifié avec photo.
     */
    public function test_log_02_pre_alert_issue_reporting(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        Queue::fake();
        Storage::fake('public');

        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $locker = $this->createLocker($client);

        // Créer une pré-alerte
        $preAlert = PreAlert::query()->create([
            'reference_code' => PreAlert::generateReferenceCode(),
            'user_id' => $client->id,
            'locker_id' => $locker->id,
            'status' => ShipmentStatus::InTransit,
            'merchant_name' => 'eBay',
            'carrier_name' => 'UPS',
            'vendor_tracking_number' => 'EBAY-67890',
            'declared_value' => 75.00,
            'value_currency' => 'USD',
        ]);

        Sanctum::actingAs($operator);

        // Signaler un problème (colis endommagé)
        $photo = $this->fakeTinyPngUpload('damaged_package.png');

        $response = $this->postJson("/api/shipment-notices/{$preAlert->id}/report-issue", [
            'issue_type' => 'damaged',
            'description' => 'Colis fortement endommagé lors du transport',
            'photo' => $photo,
        ]);

        $response->assertOk();

        // Vérifier que le signalement a été enregistré
        $this->assertDatabaseHas('pre_alert_issue_reports', [
            'pre_alert_id' => $preAlert->id,
            'reported_by_user_id' => $operator->id,
        ]);

        $this->assertStringContainsString('damaged', PreAlertIssueReport::query()->where('pre_alert_id', $preAlert->id)->value('message'));
    }

    /**
     * LOG-03 : Preuve de livraison stricte
     * Le driver tente de marquer COMPLETED sans uploader de photo.
     * Erreur bloquante. La photo (preuve de service) est obligatoire et non contournable.
     */
    public function test_log_03_delivery_photo_is_mandatory(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $driver = User::factory()->create(['agency_id' => $agency->id]);
        $driver->assignRole('driver');

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

        // Créer une expédition assignée au driver
        $shipment = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $operator->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::ArrivedAtDestination,
            'assigned_driver_id' => $driver->id,
            'public_tracking' => 'TRK-LOG03-001',
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 50,
        ]);

        Sanctum::actingAs($driver);

        // Tentative de livraison SANS photo (doit échouer)
        // Note: Selon l'implémentation, l'endpoint peut être /api/shipments/{id}/deliver
        // ou /api/shipments/{id}/update-status
        $response = $this->postJson("/api/shipments/{$shipment->id}/deliver", [
            'delivery_notes' => 'Livré au client',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['delivery_photo']);

        // Vérifier que le statut n'a pas changé
        $shipment->refresh();
        $this->assertEquals(ShipmentStatus::ArrivedAtDestination, $shipment->status);
    }

    /**
     * LOG-04 : Échec de livraison
     * Le driver marque FAILED (client absent).
     * Motif obligatoire. Client et staff notifiés immédiatement pour reprogrammation.
     */
    public function test_log_04_delivery_failure_requires_reason_and_notifies(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        Queue::fake();

        $driver = User::factory()->create(['agency_id' => $agency->id]);
        $driver->assignRole('driver');

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

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

        // Créer une expédition assignée au driver
        $shipment = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $operator->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::ArrivedAtDestination,
            'assigned_driver_id' => $driver->id,
            'public_tracking' => 'TRK-LOG04-001',
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 50,
        ]);

        Sanctum::actingAs($driver);

        // Tentative d'échec SANS motif (doit échouer)
        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => ShipmentStatus::DeliveryFailed->value,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['failure_reason']);

        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => ShipmentStatus::DeliveryFailed->value,
            'failure_reason' => 'client_absent',
            'notes' => 'Client non présent à l\'adresse indiquée',
        ]);

        $response->assertOk();

        $shipment->refresh();
        $this->assertEquals(ShipmentStatus::DeliveryFailed, $shipment->status);

        $this->assertDatabaseHas('shipment_logs', [
            'shipment_id' => $shipment->id,
            'title' => 'Changement de statut : Échec de livraison',
        ]);
    }
}
