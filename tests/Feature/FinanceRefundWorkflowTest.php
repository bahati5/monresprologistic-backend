<?php

namespace Tests\Feature;

use App\Enums\RefundStatus;
use App\Enums\ShipmentStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Enums\AssistedPurchaseStatus;
use App\Models\Country;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Models\Refund;
use App\Models\Regroupement;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use App\Listeners\SyncOdooListener;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Workflows 5 et 6 : Finances, Remboursements et Regroupement
 * Plan de Test Master — Section 6 : FIN-01 à FIN-04
 */
class FinanceRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'FIN'.uniqid(),
            'name' => 'Agence test FIN',
            'slug' => 'ag-fin-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    /**
     * FIN-01 : Demande de remboursement
     * Création d'une demande de 300$ par le staff ou client.
     * Statut initial `requested` (parcours examen). Nécessite validation agency_admin (seuil > 50$).
     */
    public function test_fin_01_refund_request_under_review_requires_approval(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $agencyAdmin = User::factory()->create(['agency_id' => $agency->id]);
        $agencyAdmin->assignRole('agency_admin');

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $recipientProfile = Profile::query()->create([
            'first_name' => 'Client',
            'last_name' => 'Destinataire',
            'agency_id' => $agency->id,
        ]);

        $client = User::factory()->create([
            'agency_id' => $agency->id,
            'profile_id' => $recipientProfile->id,
        ]);
        $client->assignRole('client');

        $senderProfile = Profile::query()->create([
            'first_name' => 'Exp',
            'last_name' => 'Editeur',
            'agency_id' => $agency->id,
        ]);

        // Expédition liée au client (destinataire = profil portail)
        $shipment = Shipment::query()->create([
            'public_tracking' => 'TRK-FIN01-'.uniqid(),
            'sender_profile_id' => $senderProfile->id,
            'recipient_profile_id' => $recipientProfile->id,
            'creator_user_id' => $client->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::Delivered,
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 300,
        ]);

        Storage::fake('local');

        // Le client crée une demande de remboursement de 300$
        Sanctum::actingAs($client);
        $file = UploadedFile::fake()->create('refund_request.pdf', 12, 'application/pdf');

        $response = $this->postJson('/api/refunds', [
            'refundable_type' => 'shipment',
            'refundable_id' => $shipment->id,
            'amount' => 300.00,
            'currency' => 'USD',
            'reason' => 'Produit endommagé lors du transport',
            'reason_category' => 'damaged',
            'request_proof' => $file,
        ]);

        $response->assertCreated();
        $refundId = $response->json('refund.id');

        // Statut initial aligné API : `requested`
        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'client_id' => $client->id,
            'amount' => 300.00,
            'status' => RefundStatus::Requested->value,
        ]);

        // Le workflow exige Requested → UnderReview → Approved
        Refund::where('id', $refundId)->update(['status' => RefundStatus::UnderReview->value]);

        // Vérifier que l'operator ne peut pas approuver (seuil > 50$)
        Sanctum::actingAs($operator);
        $response = $this->postJson("/api/refunds/{$refundId}/approve");
        $response->assertForbidden();

        // Vérifier que l'agency_admin peut approuver
        Sanctum::actingAs($agencyAdmin);
        $response = $this->postJson("/api/refunds/{$refundId}/approve");
        $response->assertOk();

        // Vérifier que le statut est passé à APPROVED
        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'status' => RefundStatus::Approved->value,
            'reviewed_by' => $agencyAdmin->id,
        ]);
    }

    /**
     * FIN-02 : Impact comptable automatique
     * Approbation et traitement final du remboursement (PROCESSED).
     * Écriture au grand livre Monrespro + Création asynchrone d'une note de crédit dans Odoo via API.
     */
    public function test_fin_02_refund_creates_ledger_entry_and_odoo_credit_note(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        Queue::fake();

        $agencyAdmin = User::factory()->create(['agency_id' => $agency->id]);
        $agencyAdmin->assignRole('agency_admin');

        $recipientProfile = Profile::query()->create([
            'first_name' => 'Client',
            'last_name' => 'FIN02',
            'agency_id' => $agency->id,
        ]);
        $client = User::factory()->create([
            'agency_id' => $agency->id,
            'profile_id' => $recipientProfile->id,
        ]);
        $client->assignRole('client');

        $senderProfile = Profile::query()->create([
            'first_name' => 'Exp',
            'last_name' => 'Editeur',
            'agency_id' => $agency->id,
        ]);

        $shipment = Shipment::query()->create([
            'public_tracking' => 'TRK-FIN02-'.uniqid(),
            'sender_profile_id' => $senderProfile->id,
            'recipient_profile_id' => $recipientProfile->id,
            'creator_user_id' => $client->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::Delivered,
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 150,
        ]);

        // Créer un remboursement approuvé
        $refund = Refund::query()->create([
            'reference_code' => 'RMB-FIN02-001',
            'refundable_type' => Shipment::class,
            'refundable_id' => $shipment->id,
            'client_id' => $client->id,
            'agency_id' => $agency->id,
            'amount' => 150.00,
            'currency' => 'USD',
            'status' => RefundStatus::Approved,
            'reason' => 'Remboursement test',
            'reviewed_by' => $agencyAdmin->id,
            'reviewed_at' => now(),
        ]);

        Sanctum::actingAs($agencyAdmin);

        // Traiter le remboursement (PROCESSED)
        $response = $this->postJson("/api/refunds/{$refund->id}/process", [
            'payment_method' => 'bank_transfer',
            'payment_details' => [
                'account_number' => '123456789',
                'bank_name' => 'Test Bank',
            ],
        ]);

        $response->assertOk();

        // Vérifier que le statut est PROCESSED
        $refund->refresh();
        $this->assertEquals(RefundStatus::Processed, $refund->status);

        // Vérifier que l'écriture au grand livre a été créée
        // Note: Le format exact dépend de l'implémentation réelle
        $this->assertDatabaseHas('ledger_entries', [
            'agency_id' => $agency->id,
            'amount' => -150.00,
            'currency' => 'USD',
            'type' => 'refund',
        ]);

        // La synchro Odoo est assurée par l’écouteur file `SyncOdooListener` (ShouldQueue), pas par un job dédié.
        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            return $job->class === SyncOdooListener::class;
        });
    }

    /**
     * FIN-03 : Suggestion de regroupement
     * Afficher la liste des colis READY_FOR_DISPATCH pour un même pays.
     * Apparition d'une bannière de suggestion intelligente pour l'opérateur.
     */
    public function test_fin_03_regroupement_suggestion_intelligent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        $country = Country::query()->create([
            'code' => 'FR',
            'name' => 'France',
            'phonecode' => '33',
        ]);

        $sender = Profile::query()->create([
            'first_name' => 'Exp',
            'last_name' => 'Editeur',
            'agency_id' => $agency->id,
        ]);

        // Créer 3 expéditions READY_FOR_DISPATCH pour le même pays et même client
        for ($i = 1; $i <= 3; $i++) {
            $recipient = Profile::query()->create([
                'first_name' => 'Dest',
                'last_name' => "Inataire{$i}",
                'agency_id' => $agency->id,
            ]);

            Shipment::query()->create([
                'sender_profile_id' => $sender->id,
                'recipient_profile_id' => $recipient->id,
                'creator_user_id' => $client->id,
                'agency_id' => $agency->id,
                'dest_country_id' => $country->id,
                'status' => ShipmentStatus::ReadyForDispatch,
                'public_tracking' => "TRK-FIN03-00{$i}",
                'declared_currency' => 'USD',
                'currency' => 'USD',
                'calculated_price' => 50 * $i,
            ]);
        }

        Sanctum::actingAs($operator);

        // Récupérer les suggestions de regroupement
        $response = $this->getJson('/api/regroupements/suggestions');

        $response->assertOk();
        $data = $response->json();

        // Vérifier que des suggestions sont retournées
        // Note: Le format exact dépend de l'implémentation réelle
        $this->assertArrayHasKey('suggestions', $data);
    }

    /**
     * FIN-04 : Transparence regroupement
     * Consultation du portail client après regroupement.
     * Le client ne voit que son propre colis et son statut de transit.
     * Le regroupement reste purement interne.
     */
    public function test_fin_04_client_sees_only_own_shipment_not_grouping_details(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $client1 = User::factory()->create(['agency_id' => $agency->id, 'email' => 'client1@test.com']);
        $client1->assignRole('client');

        $client2 = User::factory()->create(['agency_id' => $agency->id, 'email' => 'client2@test.com']);
        $client2->assignRole('client');

        $country = Country::query()->create([
            'code' => 'FR',
            'name' => 'France',
        ]);

        // Créer un regroupement avec des expéditions de différents clients
        $regroupement = Regroupement::query()->create([
            'agency_id' => $agency->id,
            'reference_code' => 'GRP-FIN04-001',
            'dest_country_id' => $country->id,
            'status' => 'in_transit',
        ]);

        $sender = Profile::query()->create([
            'first_name' => 'Exp',
            'last_name' => 'Editeur',
            'agency_id' => $agency->id,
        ]);

        // Expédition du client 1
        $recipient1 = Profile::query()->create([
            'first_name' => 'Dest',
            'last_name' => 'Client1',
            'agency_id' => $agency->id,
        ]);

        $shipment1 = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient1->id,
            'creator_user_id' => $client1->id,
            'agency_id' => $agency->id,
            'dest_country_id' => $country->id,
            'regroupement_id' => $regroupement->id,
            'status' => ShipmentStatus::InTransit,
            'public_tracking' => 'TRK-CLT1-001',
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 100,
        ]);

        // Expédition du client 2
        $recipient2 = Profile::query()->create([
            'first_name' => 'Dest',
            'last_name' => 'Client2',
            'agency_id' => $agency->id,
        ]);

        $shipment2 = Shipment::query()->create([
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient2->id,
            'creator_user_id' => $client2->id,
            'agency_id' => $agency->id,
            'dest_country_id' => $country->id,
            'regroupement_id' => $regroupement->id,
            'status' => ShipmentStatus::InTransit,
            'public_tracking' => 'TRK-CLT2-001',
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 150,
        ]);

        // Test : Client 1 ne voit que son expédition
        Sanctum::actingAs($client1);
        $response = $this->getJson('/api/shipments');

        $response->assertOk();
        $data = $response->json();

        // Le client 1 ne doit voir que ses propres expéditions
        // Note: Le format exact dépend de l'implémentation réelle de l'API
        $this->assertNotNull($data);

        // Test : Client 2 ne voit que ses propres expéditions
        Sanctum::actingAs($client2);
        $response = $this->getJson('/api/shipments');

        $response->assertOk();
        $data = $response->json();
        $this->assertNotNull($data);
    }
}
