<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(MonresproCoreDataSeeder::class);
        $this->call(LocationSeeder::class);

        $hub = Agency::firstOrCreate(
            ['code' => 'BE-HUB'],
            [
                'name' => 'Hub Europe (Belgique)',
                'slug' => 'hub-europe',
                'default_currency' => 'EUR',
                'is_active' => true,
            ]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@monrespro.local'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'agency_id' => $hub->id,
                'theme_preference' => 'system',
                'can_view_all_agencies' => true,
            ]
        );
        $admin->assignRole('super_admin');

        $client = User::firstOrCreate(
            ['email' => 'client@monrespro.local'],
            [
                'name' => 'Client Démo',
                'password' => Hash::make('password'),
                'agency_id' => $hub->id,
                'theme_preference' => 'system',
                'can_view_all_agencies' => false,
            ]
        );
        $client->assignRole('client');
        event(new Registered($client));
    }
}
