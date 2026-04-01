<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Locker;
use App\Models\Profile;
use App\Models\Setting;
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

        $adminProfile = Profile::firstOrCreate(
            ['email' => 'admin@monrespro.local'],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'phone' => '+32000000001',
                'agency_id' => $hub->id,
                'is_active' => true,
            ]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@monrespro.local'],
            [
                'profile_id' => $adminProfile->id,
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'agency_id' => $hub->id,
                'theme_preference' => 'system',
                'can_view_all_agencies' => true,
            ]
        );
        $admin->assignRole('super_admin');

        $clientProfile = Profile::firstOrCreate(
            ['email' => 'client@monrespro.local'],
            [
                'first_name' => 'Client',
                'last_name' => 'Démo',
                'phone' => '+32000000002',
                'agency_id' => $hub->id,
                'is_active' => true,
            ]
        );

        $lockerCode = $this->generateLockerNumber();

        $client = User::firstOrCreate(
            ['email' => 'client@monrespro.local'],
            [
                'profile_id' => $clientProfile->id,
                'name' => 'Client Démo',
                'password' => Hash::make('password'),
                'agency_id' => $hub->id,
                'locker_number' => $lockerCode,
                'theme_preference' => 'system',
                'can_view_all_agencies' => false,
            ]
        );
        $client->assignRole('client');

        Locker::firstOrCreate(
            ['code' => $lockerCode],
            [
                'profile_id' => $clientProfile->id,
                'user_id' => $client->id,
                'formatted_address' => str_replace(
                    '{{locker_code}}',
                    $lockerCode,
                    Setting::getValue('locker_address_template', '')
                ),
            ]
        );

        event(new Registered($client));
    }

    private function generateLockerNumber(): string
    {
        $prefix = Setting::getValue('locker_prefix', 'MRP');
        $digits = max(1, min(8, (int) Setting::getValue('locker_digits', '4')));
        $max = min(PHP_INT_MAX, (10 ** $digits) - 1);

        do {
            $num = random_int(0, $max);
            $code = $prefix . '-' . str_pad((string) $num, $digits, '0', STR_PAD_LEFT);
        } while (Locker::where('code', $code)->exists());

        return $code;
    }
}
