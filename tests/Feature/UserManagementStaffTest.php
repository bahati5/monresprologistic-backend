<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementStaffTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'StaffMgmt!Test2026';

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'UMS'.uniqid(),
            'name' => 'Agence test users',
            'slug' => 'ag-ums-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function staffUserWithProfile(User $user, bool $active = true): void
    {
        $profile = Profile::create([
            'first_name' => 'Ad',
            'last_name' => 'Min',
            'email' => $user->email,
            'phone' => '+243900'.random_int(100000, 999999),
            'is_active' => $active,
            'is_client' => false,
            'is_staff' => true,
        ]);
        $user->update(['profile_id' => $profile->id]);
    }

    public function test_store_creates_linked_staff_profile(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $admin = User::factory()->create(['agency_id' => $agency->id]);
        $admin->assignRole('super_admin');
        $this->staffUserWithProfile($admin);

        Sanctum::actingAs($admin);

        $email = 'newstaff-'.uniqid('', true).'@test.monrespro.cd';

        $response = $this->postJson('/api/users', [
            'name' => 'Jean Dupont',
            'email' => $email,
            'phone' => '+243901000001',
            'password' => self::STRONG_PASSWORD,
            'agency_uuid' => $agency->uuid,
            'role' => 'operator',
        ]);

        $response->assertCreated();

        $created = User::query()->where('email', $email)->first();
        $this->assertNotNull($created);
        $this->assertNotNull($created->profile_id);
        $this->assertSame($email, $created->profile?->email);
        $this->assertTrue((bool) $created->profile?->is_active);
    }

    public function test_toggle_active_updates_profile_and_blocks_self_deactivate(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $admin = User::factory()->create(['agency_id' => $agency->id]);
        $admin->assignRole('super_admin');
        $this->staffUserWithProfile($admin);

        $target = User::factory()->create(['agency_id' => $agency->id]);
        $target->assignRole('operator');
        $this->staffUserWithProfile($target);

        Sanctum::actingAs($admin);

        $this->postJson("/api/users/{$target->uuid}/toggle-active")->assertOk();
        $target->refresh();
        $this->assertFalse((bool) $target->profile?->is_active);

        $this->postJson("/api/users/{$admin->uuid}/toggle-active")
            ->assertStatus(422);
    }

    public function test_reset_password_allows_login_with_new_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $admin = User::factory()->create(['agency_id' => $agency->id]);
        $admin->assignRole('super_admin');
        $this->staffUserWithProfile($admin);

        $target = User::factory()->create([
            'agency_id' => $agency->id,
            'password' => bcrypt('OldPass!2026'),
        ]);
        $target->assignRole('operator');
        $this->staffUserWithProfile($target);

        Sanctum::actingAs($admin);

        $newPass = 'NewLogin!Pass2026';
        $this->postJson("/api/users/{$target->uuid}/reset-password", [
            'password' => $newPass,
            'password_confirmation' => $newPass,
        ])->assertOk();

        $target->refresh();
        $this->assertTrue(Hash::check($newPass, $target->password));
    }

    public function test_inactive_profile_cannot_login(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();

        $user = User::factory()->create([
            'agency_id' => $agency->id,
            'password' => bcrypt('SomePass!2026'),
        ]);
        $user->assignRole('operator');
        $this->staffUserWithProfile($user, active: false);

        config(['sanctum.stateful' => ['localhost', '127.0.0.1', 'testserver']]);
        $response = $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ])->postJson('/api/auth/login', [
            'login' => $user->email,
            'password' => 'SomePass!2026',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.login.0', 'Ce compte est désactivé.');
    }
}
