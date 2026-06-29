<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Associe au compte super-admin tous les rôles Spatie `web` **staff** après RbacSeeder,
 * sauf {@see self::EXCLUDED_FROM_SUPER_ADMIN} (portail client / chauffeur : vues et filtres
 * basés sur `hasRole('client')` / chauffeur ne doivent pas s'appliquer à la direction).
 *
 * Prérequis : {@see SuperAdminSeeder} puis {@see RbacSeeder}.
 *
 * Identifiants : identiques à SuperAdminSeeder — `SEED_SUPER_ADMIN_EMAIL` / `SEED_SUPER_ADMIN_PASSWORD`
 * (voir `.env.example`).
 */
class FullAccessSuperAdminSeeder extends Seeder
{
    /** Rôles jamais cumulés avec le compte super-admin (évite listes vides / périmètre chauffeur). */
    private const EXCLUDED_FROM_SUPER_ADMIN = ['client', 'driver'];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $email = env('SEED_SUPER_ADMIN_EMAIL', 'admin@monrespro.local');

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $this->command?->warn("FullAccessSuperAdminSeeder : aucun utilisateur « {$email} ». Lancez d'abord SuperAdminSeeder.");

            return;
        }

        $roleNames = Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->reject(fn (string $name) => in_array($name, self::EXCLUDED_FROM_SUPER_ADMIN, true))
            ->values()
            ->all();

        if ($roleNames === []) {
            $this->command?->warn('FullAccessSuperAdminSeeder : aucun rôle web staff en base après exclusion client/chauffeur.');

            return;
        }

        $user->syncRoles($roleNames);

        $hasCustomPassword = env('SEED_SUPER_ADMIN_PASSWORD') !== null && env('SEED_SUPER_ADMIN_PASSWORD') !== '';

        $this->command?->info('');
        $this->command?->info('=== Super-admin accès maximal (rôles web staff, hors client/chauffeur) ===');
        $this->command?->info("  E-mail        : {$email}");
        $this->command?->info(
            $hasCustomPassword
                ? '  Mot de passe : celui défini dans SEED_SUPER_ADMIN_PASSWORD'
                : '  Mot de passe : password (défaut local — définissez SEED_SUPER_ADMIN_PASSWORD en prod)'
        );
        $this->command?->info('  Rôles         : '.implode(', ', $roleNames));
        $this->command?->info('');
    }
}
