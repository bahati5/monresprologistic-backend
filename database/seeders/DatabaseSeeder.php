<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Base minimale : rôles / permissions, référentiel pays (drapeaux via emoji), super admin.
     * Le reste (agences, statuts, lignes commerciales, clients, etc.) se crée dans l’application.
     *
     * Remise à zéro typique : php artisan migrate:fresh --seed
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            LocationSeeder::class,
            SuperAdminSeeder::class,
            MerchantSeeder::class,
        ]);
    }
}
