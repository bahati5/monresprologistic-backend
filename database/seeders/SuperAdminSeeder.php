<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SEED_SUPER_ADMIN_EMAIL', 'admin@monrespro.local');
        $password = env('SEED_SUPER_ADMIN_PASSWORD', 'password');

        $profile = Profile::updateOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'phone' => '+0000000000',
                'agency_id' => null,
                'is_active' => true,
                'is_staff' => true,
                'is_client' => false,
            ]
        );

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'profile_id' => $profile->id,
                'name' => 'Super Admin',
                'password' => Hash::make($password),
                'agency_id' => null,
                'theme_preference' => 'system',
                'can_view_all_agencies' => true,
            ]
        );

        // Compte « direction » : vue globale (canAccessAllAgencies = isSuperAdmin), attributs alignés à chaque seed.
        $user->forceFill([
            'profile_id' => $profile->id,
            'can_view_all_agencies' => true,
        ])->save();

        // Rôle unique avec toutes les permissions (RolesAndPermissionsSeeder).
        $user->syncRoles(['super_admin']);

        $this->command?->info("Super admin prêt : {$email} (mot de passe défini par SEED_SUPER_ADMIN_PASSWORD ou valeur par défaut locale).");
    }
}
