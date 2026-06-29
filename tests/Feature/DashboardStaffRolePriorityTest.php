<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Priorité des branches du dashboard : un utilisateur staff ne doit pas être
 * réduit au tableau « douane » uniquement parce qu’il cumule customs_agent.
 */
class DashboardStaffRolePriorityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->agency = Agency::query()->create([
            'code' => 'DSH'.uniqid(),
            'name' => 'Agence Dashboard',
            'slug' => 'ag-dsh-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_super_admin_cumulating_customs_agent_gets_admin_dashboard(): void
    {
        $user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'can_view_all_agencies' => true,
        ]);
        $user->syncRoles(['super_admin', 'customs_agent']);

        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard_type', 'admin');
    }

    public function test_agency_admin_cumulating_customs_agent_gets_admin_dashboard(): void
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $user->syncRoles(['agency_admin', 'customs_agent']);

        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard_type', 'admin');
    }

    public function test_operator_cumulating_customs_agent_gets_operator_dashboard(): void
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $user->syncRoles(['operator', 'customs_agent']);

        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard_type', 'operator');
    }

    public function test_customs_agent_only_gets_customs_dashboard(): void
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $user->syncRoles(['customs_agent']);

        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard_type', 'customs');
    }
}
