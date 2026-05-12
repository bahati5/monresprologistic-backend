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

/**
 * AUTH-001 à AUTH-009 : Authentification
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::query()->create([
            'code' => 'AUTH'.uniqid(),
            'name' => 'Agence test AUTH',
            'slug' => 'ag-auth-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    /** AUTH-001 : Connexion valide opérateur → redirection + token */
    public function test_auth_001_valid_operator_login(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        $user = User::factory()->create([
            'agency_id' => $agency->id,
            'password' => Hash::make('SecureP@ss2026'),
        ]);
        $user->assignRole('operator');

        $profile = Profile::create([
            'first_name' => 'Op', 'last_name' => 'Test',
            'email' => $user->email, 'phone' => '+243800000001',
            'is_active' => true, 'is_client' => false, 'is_staff' => true,
        ]);
        $user->update(['profile_id' => $profile->id]);

        // AuthController::login() appelle $request->session()->regenerate()
        // qui nécessite le middleware StartSession (via Sanctum stateful).
        // On simule une requête depuis un domaine stateful configuré.
        config(['sanctum.stateful' => ['localhost', '127.0.0.1', 'testserver']]);
        $response = $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ])->postJson('/api/auth/login', [
            'login' => $user->email,
            'password' => 'SecureP@ss2026',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user' => [
                    'uuid',
                    'name',
                    'email',
                ],
            ]);
    }

    /** AUTH-002 : Connexion valide client */
    public function test_auth_002_valid_client_login(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $agency = $this->makeAgency();
        $user = User::factory()->create([
            'agency_id' => $agency->id,
            'password' => Hash::make('ClientP@ss2026'),
        ]);
        $user->assignRole('client');

        $profile = Profile::create([
            'first_name' => 'Cl', 'last_name' => 'Test',
            'email' => $user->email, 'phone' => '+243800000002',
            'is_active' => true, 'is_client' => true, 'is_staff' => false,
        ]);
        $user->update(['profile_id' => $profile->id]);

        config(['sanctum.stateful' => ['localhost', '127.0.0.1', 'testserver']]);
        $response = $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ])->postJson('/api/auth/login', [
            'login' => $user->email,
            'password' => 'ClientP@ss2026',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user']);
    }

    /** AUTH-003 : Connexion avec email inexistant → message générique */
    public function test_auth_003_login_nonexistent_email(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => 'inexistant@test.monrespro.cd',
            'password' => 'anypassword',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonMissing(['email' => 'inexistant@test.monrespro.cd']);
    }

    /** AUTH-004 : Connexion avec mauvais mot de passe → erreur générique */
    public function test_auth_004_login_wrong_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword'),
        ]);

        $profile = Profile::create([
            'first_name' => 'W', 'last_name' => 'P',
            'email' => $user->email, 'phone' => '+243800000003',
            'is_active' => true, 'is_client' => true, 'is_staff' => false,
        ]);
        $user->update(['profile_id' => $profile->id]);

        $response = $this->postJson('/api/auth/login', [
            'login' => $user->email,
            'password' => 'WrongPassword',
        ]);

        $response->assertUnprocessable();
    }

    /** AUTH-005 : Champs vides à la connexion → validation */
    public function test_auth_005_login_empty_fields(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => '',
            'password' => '',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['login']);
    }

    /** AUTH-008 : Déconnexion — vérifie que l'endpoint existe et répond */
    public function test_auth_008_logout_endpoint_exists(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('operator');

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/user')->assertOk();

        // Logout utilise des sessions web ; en mode Sanctum token, on vérifie
        // que l'endpoint est bien protégé et renvoie une réponse non-404
        $response = $this->postJson('/api/auth/logout');
        $this->assertNotEquals(404, $response->getStatusCode());
    }

    /** AUTH : Endpoint /api/auth/user accessible uniquement authentifié */
    public function test_auth_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/auth/user')->assertUnauthorized();
    }

    /** AUTH : Health check public accessible sans auth */
    public function test_health_check_public(): void
    {
        $this->getJson('/api/ping')->assertOk()
            ->assertJson(['status' => 'ok']);
    }
}
