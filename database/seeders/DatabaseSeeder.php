<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Base : rôles / permissions (Spatie), **RbacSeeder** (menus, pages, groupes, `role_id`),
     * référentiel pays, super admin, logistique, plateforme démo.
     *
     * Remise à zéro typique : php artisan migrate:fresh --seed
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            LocationSeeder::class,
            SuperAdminSeeder::class,
            PickupFailureReasonsSeeder::class,
            MerchantSeeder::class,
            LogisticsSeeder::class,
            FullPlatformSeeder::class,
            RbacSeeder::class,
        ]);
    }
}
