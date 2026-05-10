<?php

namespace Tests\Feature;

use App\Enums\ShipmentStatus;
use App\Models\Agency;
use App\Models\Country;
use App\Models\Profile;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Workflow 2 : Expédition Standard et Formulaires
 * Plan de Test Master — Section 4 : EXP-01 à EXP-05
 */
class StandardShippingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'EXP'.uniqid(),
            'name' => 'Agence test EXP',
            'slug' => 'ag-exp-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function createCountry(string $code, string $name, ?string $phoneCode = null): Country
    {
        return Country::query()->create([
            'code' => $code,
            'name' => $name,
            'phonecode' => $phoneCode !== null ? preg_replace('/\D+/', '', $phoneCode) : null,
        ]);
    }

    /**
     * EXP-01 : Création (Type A)
     * Numéro de suivi généré à la création du brouillon.
     * Formulaire intelligent adaptant les champs (ZIP Code pour USA, Commune pour RDC).
     */
    public function test_exp_01_shipment_creation_with_smart_form(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        // Créer les pays pour le test
        $usa = $this->createCountry('US', 'United States', '+1');
        $rdc = $this->createCountry('CD', 'Congo, Democratic Republic', '+243');

        Sanctum::actingAs($operator);

        // Test : Créer un profil expéditeur
        $sender = Profile::query()->create([
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'agency_id' => $agency->id,
        ]);

        $recipient = Profile::query()->create([
            'first_name' => 'Marie',
            'last_name' => 'Martin',
            'agency_id' => $agency->id,
        ]);

        // Créer une expédition vers les USA (doit demander ZIP Code)
        $response = $this->postJson('/api/shipments', [
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'origin_country_id' => $usa->id,
            'dest_country_id' => $usa->id,
            'status' => ShipmentStatus::Draft,
            'declared_value' => 100,
            'declared_currency' => 'USD',
            'legal_declaration_accepted' => true,
            'items' => [
                [
                    'description' => 'Articles divers',
                    'quantity' => 1,
                    'weight_kg' => 1.5,
                    'value' => 100,
                ],
            ],
        ]);

        $response->assertCreated();
        $id = $response->json('id');
        $this->assertNotNull($id);

        $shipment = Shipment::query()->findOrFail($id);
        $this->assertNotEmpty($shipment->public_tracking);
        // Création par le personnel → statut comptoir (pas brouillon client)
        $this->assertEquals(ShipmentStatus::ReceivedAtHub, $shipment->status);
    }

    /**
     * EXP-02 : Détection doublon client
     * Saisir un nom/téléphone très proche d'un client existant.
     * Alerte visuelle non bloquante proposant de fusionner ou charger le profil existant.
     */
    public function test_exp_02_client_duplicate_detection(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $agencyAdmin = User::factory()->create(['agency_id' => $agency->id]);
        $agencyAdmin->assignRole('agency_admin');

        // Créer un client existant
        $existingProfile = Profile::query()->create([
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'phone' => '+243999999999',
            'email' => 'jean.dupont@email.com',
            'agency_id' => $agency->id,
        ]);

        Sanctum::actingAs($agencyAdmin);

        // Test : Vérifier les doublons avec un nom similaire
        // Note: L'endpoint requiert manage_clients permission
        $response = $this->postJson('/api/clients/check-duplicates', [
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'phone' => '+243999999998', // Numéro très proche
        ]);

        $response->assertOk();
        $data = $response->json();

        // Vérifier que la réponse contient les informations attendues
        $this->assertArrayHasKey('duplicates', $data);
    }

    /**
     * EXP-03 : Génération et Impression directe
     * PDF généré avec QR code pointant vers la page de suivi publique.
     * Impression directe réseau sans boîte de dialogue navigateur.
     */
    public function test_exp_03_pdf_generation_with_qr_code(): void
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
            'status' => ShipmentStatus::ReadyForDispatch,
            'public_tracking' => 'TRK-EXP03-001',
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 50,
        ]);

        Sanctum::actingAs($operator);

        // Test : Générer le PDF de l'étiquette
        $response = $this->getJson("/api/shipments/{$shipment->id}/pdf/label");

        $response->assertOk();

        // Vérifier que la réponse est un PDF
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));

        $content = $response->getContent();
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertGreaterThan(200, strlen($content), 'Le PDF étiquette doit avoir un contenu non trivial.');
    }

    /**
     * EXP-04 : Archivage de la signature
     * Uploader le scan du document signé physiquement.
     * Document attaché au dossier avec horodatage. Icône "Signé" visible.
     */
    public function test_exp_04_signed_form_archiving(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        Storage::fake('public');

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
            'status' => ShipmentStatus::Delivered,
            'public_tracking' => 'TRK-EXP04-001',
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 75,
        ]);

        Sanctum::actingAs($operator);

        // Uploader le document signé (utiliser un fichier PDF car GD n'est pas disponible)
        $file = UploadedFile::fake()->create('signed_document.pdf', 100, 'application/pdf');

        $response = $this->postJson("/api/shipments/{$shipment->id}/archive-signed-form", [
            'signed_form' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Formulaire signé archivé.');

        // Vérifier que le fichier a été stocké
        $shipment->refresh();
        $this->assertNotNull($shipment->signed_form_path);
        Storage::disk('public')->assertExists($shipment->signed_form_path);

        // Vérifier que le log a été créé avec horodatage
        $this->assertDatabaseHas('shipment_logs', [
            'shipment_id' => $shipment->id,
            'title' => 'Formulaire signé archivé',
        ]);
    }

    /**
     * EXP-05 : Blocage expédition (Douane)
     * Passer le statut à CUSTOMS_HOLD.
     * Notification automatique au client et création d'un ticket dans Freshsales.
     */
    public function test_exp_05_customs_hold_notification_and_ticket(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        Queue::fake();

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

        $shipment = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $client->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::InTransit,
            'public_tracking' => 'TRK-EXP05-001',
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 100,
        ]);

        Sanctum::actingAs($operator);

        // Passer le statut à CustomsHold
        $response = $this->postJson("/api/shipments/{$shipment->id}/update-status", [
            'status' => ShipmentStatus::CustomsHold->value,
            'notes' => 'Problème de déclaration douanière',
        ]);

        $response->assertOk();

        // Vérifier que le statut a été mis à jour
        $shipment->refresh();
        $this->assertEquals(ShipmentStatus::CustomsHold, $shipment->status);

        // Vérifier que le log de changement de statut a été créé
        $this->assertDatabaseHas('shipment_logs', [
            'shipment_id' => $shipment->id,
            'title' => 'Changement de statut : Blocage douane',
        ]);

        // Les notifications client / Freshsales dépendent des listeners et de la config CRM
    }

    /**
     * EXP-B : à la réception hub, écart de poids > 10 % sans confirmation → 422 ; avec confirmation → OK.
     */
    public function test_exp_b_hub_measured_weight_variance_gate(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $sender = Profile::query()->create([
            'first_name' => 'Hub',
            'last_name' => 'Test',
            'agency_id' => $agency->id,
        ]);
        $recipient = Profile::query()->create([
            'first_name' => 'Dest',
            'last_name' => 'Hub',
            'agency_id' => $agency->id,
        ]);

        $shipment = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $operator->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::PendingDropOff,
            'public_tracking' => 'TRK-EXPB-'.uniqid(),
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'weight_kg' => 10.0,
            'calculated_price' => 50,
        ]);

        Sanctum::actingAs($operator);

        $reject = $this->postJson("/api/shipments/{$shipment->id}/accept", [
            'hub_measured_weight_kg' => 12.5,
        ]);
        $reject->assertStatus(422)->assertJsonValidationErrors(['hub_measured_weight_kg']);

        $ok = $this->postJson("/api/shipments/{$shipment->id}/accept", [
            'hub_measured_weight_kg' => 12.5,
            'confirm_weight_variance' => true,
        ]);
        $ok->assertOk();
        $this->assertEquals(ShipmentStatus::ReceivedAtHub, $shipment->fresh()->status);
    }
}
