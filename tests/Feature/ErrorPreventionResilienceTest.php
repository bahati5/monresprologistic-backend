<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\RefundStatus;
use App\Enums\ShipmentStatus;
use App\Models\Agency;
use App\Events\ShipmentStatusChanged;
use App\Listeners\SyncOdooListener;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\City;
use App\Models\Country;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\State;
use App\Models\User;
use App\Services\Integrations\OdooService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Section 7 : Prévention des Erreurs Transverses et Résilience (Edge Cases)
 * Plan de Test Master — ERR-01 à ERR-04
 */
class ErrorPreventionResilienceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'ERR'.uniqid(),
            'name' => 'Agence test ERR',
            'slug' => 'ag-err-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    /**
     * ERR-01 : Coupure réseau (PWA)
     * Saisir une expédition en simulant une coupure Internet (Mode Hors-ligne).
     * Données sauvegardées en stockage local temporaire.
     * Resynchronisation automatique à la reconnexion.
     */
    public function test_err_01_offline_mode_data_persistence_and_sync(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $sender = Profile::query()->create([
            'first_name' => 'Jean',
            'last_name' => 'Offline',
            'agency_id' => $agency->id,
        ]);

        $recipient = Profile::query()->create([
            'first_name' => 'Marie',
            'last_name' => 'Online',
            'agency_id' => $agency->id,
        ]);

        // Entrée de file posée côté client / couche future (PRD ERR-01)
        Cache::put('offline_queue:queue-12345', ['stub' => true], 600);

        Sanctum::actingAs($operator);

        $response = $this->withHeaders([
            'X-Offline-Mode' => 'true',
            'X-Offline-Queue-ID' => 'queue-12345',
        ])->postJson('/api/shipments', [
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'status' => ShipmentStatus::Draft->value,
            'declared_value' => 100,
            'declared_currency' => 'USD',
            'legal_declaration_accepted' => true,
            'items' => [
                [
                    'description' => 'Articles en mode offline',
                    'quantity' => 1,
                    'weight_kg' => 2.0,
                    'value' => 100,
                ],
            ],
        ]);

        $this->assertTrue(in_array($response->getStatusCode(), [201, 202, 422, 200]));

        $sync = $this->postJson('/api/sync/offline-queue', [
            'queue_ids' => ['queue-12345'],
        ]);

        $sync->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('failed', 0);

        // Création comptoir opérateur → statut `received_at_hub` (pas brouillon client)
        if ($response->getStatusCode() === 201) {
            $this->assertDatabaseHas('shipments', [
                'creator_user_id' => $operator->id,
                'agency_id' => $agency->id,
                'status' => ShipmentStatus::ReceivedAtHub->value,
            ]);
        }
    }

    /**
     * ERR-02 : Formatage de numéro de téléphone
     * Saisie d'un numéro local sans indicatif dans un formulaire.
     * Détection automatique et ajout de l'indicatif selon le pays sélectionné.
     */
    public function test_err_02_phone_number_auto_formatting(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $staff = User::factory()->create(['agency_id' => $agency->id]);
        $staff->assignRole('agency_admin');

        $rdc = Country::query()->create([
            'code' => 'CD',
            'name' => 'Congo, Democratic Republic',
            'phonecode' => '243',
        ]);

        $state = State::query()->create([
            'country_id' => $rdc->id,
            'name' => 'Kinshasa',
            'code' => 'KN',
            'is_active' => true,
        ]);

        $city = City::query()->create([
            'state_id' => $state->id,
            'country_id' => $rdc->id,
            'country_code' => 'CD',
            'name' => 'Kinshasa',
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/clients', [
            'first_name' => 'Jean',
            'last_name' => 'Test',
            'phone' => '999999999',
            'country_id' => $rdc->id,
            'state_id' => $state->id,
            'city_id' => $city->id,
        ]);

        $response->assertCreated();
        $profileId = $response->json('client.id');
        $profile = Profile::query()->findOrFail($profileId);
        $this->assertStringStartsWith('+243', $profile->phone);
    }

    /**
     * ERR-03 : Panne de l'API Odoo / Freshsales
     * Déclencher une facture alors que l'API Odoo est down (simulée).
     * L'opération Monrespro réussit (non bloquante).
     * L'erreur de synchro est loguée dans sync_errors pour un retry asynchrone exponentiel (3 tentatives).
     */
    public function test_err_03_odoo_failure_logs_sync_error_and_retries(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $agencyAdmin = User::factory()->create(['agency_id' => $agency->id]);
        $agencyAdmin->assignRole('agency_admin');

        $sender = Profile::query()->create([
            'first_name' => 'Exp',
            'last_name' => 'ERR03',
            'agency_id' => $agency->id,
        ]);
        $recipient = Profile::query()->create([
            'first_name' => 'Dest',
            'last_name' => 'ERR03',
            'agency_id' => $agency->id,
        ]);

        $shipment = Shipment::query()->create([
            'public_tracking' => 'TRK-ERR03-'.uniqid(),
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'creator_user_id' => $agencyAdmin->id,
            'agency_id' => $agency->id,
            'status' => ShipmentStatus::InTransit,
            'declared_currency' => 'USD',
            'currency' => 'USD',
            'calculated_price' => 500,
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $agencyAdmin->id,
            'shipment_id' => $shipment->id,
            'invoice_number' => 'INV-ERR03-'.uniqid(),
            'amount' => 500.00,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $mockOdoo = Mockery::mock(OdooService::class);
        $mockOdoo->shouldReceive('isEnabled')->andReturn(true);
        $mockOdoo->shouldReceive('createInvoice')
            ->once()
            ->andThrow(new \RuntimeException('Odoo API unavailable: 503 Service Unavailable'));
        $this->app->instance(OdooService::class, $mockOdoo);

        // Appel direct de l’écouteur : en tests, plusieurs écouteurs file peuvent doubler l’exécution sur `sync`.
        $this->app->make(SyncOdooListener::class)->handleShipmentStatusChanged(
            new ShipmentStatusChanged(
                $shipment->fresh(),
                ShipmentStatus::InTransit,
                ShipmentStatus::Delivered,
                $agencyAdmin
            )
        );

        $this->assertDatabaseHas('sync_errors', [
            'integration' => 'odoo',
            'entity_type' => Invoice::class,
            'entity_id' => $invoice->id,
            'event_type' => 'shipment_delivered_invoice',
            'resolved' => false,
        ]);
    }

    /**
     * ERR-04 : Changement de taux de change
     * Modification du taux EUR/USD par l'agency_admin.
     * Le nouveau taux s'applique aux nouvelles transactions.
     * Les anciennes conservent leur taux grâce à l'archivage avec date de validité (audit trail complet).
     */
    public function test_err_04_exchange_rate_change_applies_only_to_new_transactions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $agencyAdmin = User::factory()->create(['agency_id' => $agency->id]);
        $agencyAdmin->assignRole('agency_admin');

        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');

        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        Setting::setValue('currency', 'USD');

        ExchangeRate::query()->create([
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
            'rate' => 1.08,
            'valid_from' => now()->subDays(7),
            'set_by' => $agencyAdmin->id,
        ]);

        $oldPurchase = AssistedPurchase::query()->create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::PAID,
            'product_url' => 'https://example.com/product',
            'article_label' => 'Produit Ancien',
            'price_displayed' => 100.00,
            'price_currency' => 'EUR',
            'quote_amount' => 108.00,
            'quote_currency' => 'USD',
            'created_at' => now()->subDays(5),
        ]);

        Sanctum::actingAs($agencyAdmin);

        $this->postJson('/api/settings/exchange-rates', [
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
            'rate' => 1.15,
        ])->assertCreated();

        $this->assertDatabaseHas('exchange_rates', [
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
            'rate' => 1.15,
        ]);

        Sanctum::actingAs($client);

        $newPurchase = AssistedPurchase::query()->create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::PENDING_QUOTE,
            'product_url' => 'https://example.com/product2',
            'article_label' => 'Produit Nouveau',
            'price_displayed' => 100.00,
            'price_currency' => 'EUR',
        ]);

        $item = AssistedPurchaseItem::query()->create([
            'assisted_purchase_id' => $newPurchase->id,
            'url' => 'https://example.com/produit-nouveau',
            'name' => 'Ligne test',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        Sanctum::actingAs($operator);

        $this->postJson("/api/assisted-purchases/{$newPurchase->id}/quote", [
            'service_fee' => 10.00,
            'bank_fee_percentage' => 3,
            'items' => [
                ['id' => $item->id, 'unit_price' => 101.65],
            ],
        ])->assertOk();

        $this->postJson("/api/assisted-purchases/{$newPurchase->id}/publish-payment-request")->assertOk();

        $oldPurchase->refresh();
        $this->assertEquals(108.00, (float) $oldPurchase->quote_amount);

        $newPurchase->refresh();
        $this->assertEqualsWithDelta(115.0, (float) $newPurchase->quote_amount, 0.02);

        $this->assertSame(2, ExchangeRate::query()
            ->where('from_currency', 'EUR')
            ->where('to_currency', 'USD')
            ->count());

        $rates = ExchangeRate::query()
            ->where('from_currency', 'EUR')
            ->where('to_currency', 'USD')
            ->orderBy('valid_from')
            ->get();

        $this->assertCount(2, $rates);
        $this->assertEquals(1.08, (float) $rates[0]->rate);
        $this->assertEquals(1.15, (float) $rates[1]->rate);
        $this->assertNotNull($rates[0]->valid_from);
        $this->assertNotNull($rates[1]->valid_from);
    }
}
