<?php

namespace Tests\Unit;

use App\Models\Agency;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCanAccessAllAgenciesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->agency = Agency::factory()->create();
    }

    public function test_super_admin_always_has_global_agency_access(): void
    {
        $noAgency = User::factory()->create([
            'agency_id' => null,
            'can_view_all_agencies' => false,
        ]);
        $noAgency->assignRole('super_admin');
        $this->assertTrue($noAgency->canAccessAllAgencies());

        $withAgencyFlagOff = User::factory()->create([
            'agency_id' => $this->agency->id,
            'can_view_all_agencies' => false,
        ]);
        $withAgencyFlagOff->assignRole('super_admin');
        $this->assertTrue($withAgencyFlagOff->canAccessAllAgencies());

        $withAgencyFlagOn = User::factory()->create([
            'agency_id' => $this->agency->id,
            'can_view_all_agencies' => true,
        ]);
        $withAgencyFlagOn->assignRole('super_admin');
        $this->assertTrue($withAgencyFlagOn->canAccessAllAgencies());
    }

    public function test_non_super_admin_never_has_multi_agency_access_via_this_method(): void
    {
        $agencyAdmin = User::factory()->create([
            'agency_id' => $this->agency->id,
            'can_view_all_agencies' => true,
        ]);
        $agencyAdmin->assignRole('agency_admin');
        $this->assertFalse($agencyAdmin->canAccessAllAgencies());

        $operator = User::factory()->create([
            'agency_id' => $this->agency->id,
            'can_view_all_agencies' => true,
        ]);
        $operator->assignRole('operator');
        $this->assertFalse($operator->canAccessAllAgencies());
    }
}
