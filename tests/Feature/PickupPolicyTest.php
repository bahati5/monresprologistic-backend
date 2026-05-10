<?php

namespace Tests\Feature;

use App\Enums\PickupStatus;
use App\Models\Agency;
use App\Models\Pickup;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PickupPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'TST'.uniqid(),
            'name' => 'Agence test',
            'slug' => 'ag-test-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_driver_cannot_create_pickup_via_api(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        $driver = User::factory()->create(['agency_id' => $agency->id]);
        $driver->assignRole('driver');

        Sanctum::actingAs($driver);

        $this->postJson('/api/pickups', [
            'address' => '123 rue test',
        ])->assertForbidden();
    }

    public function test_operator_can_create_pickup(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        $operator = User::factory()->create(['agency_id' => $agency->id]);
        $operator->assignRole('operator');
        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        Sanctum::actingAs($operator);

        $this->postJson('/api/pickups', [
            'client_id' => $client->id,
            'address' => '10 avenue principale',
        ])->assertCreated();
    }

    public function test_assigned_driver_can_update_status(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        $driver = User::factory()->create(['agency_id' => $agency->id]);
        $driver->assignRole('driver');
        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        $pickup = Pickup::query()->create([
            'user_id' => $client->id,
            'agency_id' => $agency->id,
            'status' => PickupStatus::DriverAssigned,
            'assigned_driver_id' => $driver->id,
            'latitude' => 0,
            'longitude' => 0,
            'address_text' => 'Adresse',
        ]);

        Sanctum::actingAs($driver);

        $this->postJson("/api/pickups/{$pickup->id}/update-status", [
            'status' => PickupStatus::Accepted->value,
        ])->assertOk();
    }

    public function test_unassigned_driver_cannot_update_pickup(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        $driver = User::factory()->create(['agency_id' => $agency->id]);
        $driver->assignRole('driver');
        $otherDriver = User::factory()->create(['agency_id' => $agency->id]);
        $otherDriver->assignRole('driver');
        $client = User::factory()->create(['agency_id' => $agency->id]);
        $client->assignRole('client');

        $pickup = Pickup::query()->create([
            'user_id' => $client->id,
            'agency_id' => $agency->id,
            'status' => PickupStatus::DriverAssigned,
            'assigned_driver_id' => $otherDriver->id,
            'latitude' => 0,
            'longitude' => 0,
            'address_text' => 'Adresse',
        ]);

        Sanctum::actingAs($driver);

        $this->postJson("/api/pickups/{$pickup->id}/update-status", [
            'status' => PickupStatus::Accepted->value,
        ])->assertForbidden();
    }
}
