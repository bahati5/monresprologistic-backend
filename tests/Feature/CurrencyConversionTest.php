<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\ExchangeRate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DEV-001 à DEV-009 : Conversion de devises
 */
class CurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->agency = Agency::query()->create([
            'code' => 'DEV'.uniqid(), 'name' => 'Agence Devises',
            'slug' => 'ag-dev-'.uniqid(), 'default_currency' => 'USD', 'is_active' => true,
        ]);

        $this->admin = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->admin->assignRole('super_admin');
    }

    /** DEV-001 : Configuration taux EUR/USD */
    public function test_dev_001_record_exchange_rate(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/settings/exchange-rates', [
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
            'rate' => 1.1053,
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('exchange_rates', [
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
        ]);
    }

    /** DEV-002 : Archivage de l'ancien taux à la mise à jour */
    public function test_dev_002_old_rate_archived(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/settings/exchange-rates', [
            'from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 1.09,
        ])->assertSuccessful();

        $this->postJson('/api/settings/exchange-rates', [
            'from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 1.1053,
        ])->assertSuccessful();

        $rates = ExchangeRate::where('from_currency', 'EUR')
            ->where('to_currency', 'USD')
            ->orderByDesc('id')
            ->get();

        $this->assertGreaterThanOrEqual(2, $rates->count(), 'Deux entrées de taux doivent exister');
    }

    /** DEV-003 : Conversion EUR → USD */
    public function test_dev_003_convert_eur_to_usd(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/settings/exchange-rates', [
            'from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 1.10,
        ])->assertSuccessful();

        $response = $this->postJson('/api/currency/convert', [
            'amount' => 100,
            'from' => 'EUR',
            'to' => 'USD',
        ]);

        $response->assertOk();
        $converted = $response->json('converted_amount') ?? $response->json('result');
        $this->assertNotNull($converted);
    }

    /** DEV-004 : Conversion GBP → USD */
    public function test_dev_004_convert_gbp_to_usd(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/settings/exchange-rates', [
            'from_currency' => 'GBP', 'to_currency' => 'USD', 'rate' => 1.28,
        ])->assertSuccessful();

        $response = $this->postJson('/api/currency/convert', [
            'amount' => 100,
            'from' => 'GBP',
            'to' => 'USD',
        ]);

        $response->assertOk();
    }

    /** DEV-005 : Conversion CDF → USD */
    public function test_dev_005_convert_cdf_to_usd(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/settings/exchange-rates', [
            'from_currency' => 'CDF', 'to_currency' => 'USD', 'rate' => 0.000355,
        ])->assertSuccessful();

        $response = $this->postJson('/api/currency/convert', [
            'amount' => 100000,
            'from' => 'CDF',
            'to' => 'USD',
        ]);

        $response->assertOk();
    }

    /** Client ne peut pas accéder à /currency/convert (vérifié dans ExchangeRateApiTest, re-testé) */
    public function test_client_cannot_convert_currency(): void
    {
        $client = User::factory()->create(['agency_id' => $this->agency->id]);
        $client->assignRole('client');
        Sanctum::actingAs($client);

        $this->postJson('/api/currency/convert', [
            'amount' => 100, 'from' => 'EUR', 'to' => 'USD',
        ])->assertForbidden();
    }

    /** Operator ne peut pas enregistrer un taux */
    public function test_operator_cannot_manage_rates(): void
    {
        $operator = User::factory()->create(['agency_id' => $this->agency->id]);
        $operator->assignRole('operator');
        Sanctum::actingAs($operator);

        $this->postJson('/api/settings/exchange-rates', [
            'from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 1.10,
        ])->assertForbidden();
    }

    /** Liste des taux accessible par admin */
    public function test_list_exchange_rates(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/settings/exchange-rates', [
            'from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 1.10,
        ])->assertSuccessful();

        $this->getJson('/api/settings/exchange-rates')->assertOk();
    }
}
