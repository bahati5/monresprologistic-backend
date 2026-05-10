<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExchangeRateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_record_rate_and_convert_currency(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        Sanctum::actingAs($user);

        $this->postJson('/api/settings/exchange-rates', [
            'from_currency' => 'EUR',
            'to_currency' => 'CDF',
            'rate' => 2500,
        ])->assertCreated();

        $this->postJson('/api/currency/convert', [
            'amount' => 10,
            'from' => 'EUR',
            'to' => 'CDF',
        ])
            ->assertOk()
            ->assertJsonFragment(['converted_amount' => 25000.0]);

        $this->getJson('/api/settings/exchange-rates')
            ->assertOk()
            ->assertJsonPath('current_rates.0.from_currency', 'EUR');
    }

    public function test_client_cannot_access_currency_convert(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('client');

        Sanctum::actingAs($user);

        $this->postJson('/api/currency/convert', [
            'amount' => 1,
            'from' => 'EUR',
            'to' => 'USD',
        ])->assertForbidden();
    }
}
