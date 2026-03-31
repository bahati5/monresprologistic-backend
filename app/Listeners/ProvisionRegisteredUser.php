<?php

namespace App\Listeners;

use App\Models\Agency;
use App\Models\Locker;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Str;

class ProvisionRegisteredUser
{
    public function handle(Registered $event): void
    {
        /** @var User $user */
        $user = $event->user;

        if ($user->hasAnyRole(['super_admin', 'agency_admin', 'operator', 'driver', 'customs_agent'])) {
            return;
        }

        if (! $user->hasRole('client')) {
            $user->assignRole('client');
        }

        $agency = Agency::query()->orderBy('id')->first();
        if ($agency && ! $user->agency_id) {
            $user->update(['agency_id' => $agency->id]);
        }

        if ($user->locker) {
            return;
        }

        $code = $this->uniqueLockerCode();
        $template = Setting::getValue(
            'locker_address_template',
            "Monrespro Hub EU\n{{locker_code}}\n1000 Bruxelles, Belgique"
        );
        $address = str_replace('{{locker_code}}', $code, $template ?? '');

        Locker::query()->create([
            'user_id' => $user->id,
            'code' => $code,
            'formatted_address' => $address,
        ]);
    }

    protected function uniqueLockerCode(): string
    {
        do {
            $code = 'MRP-'.strtoupper(Str::random(4));
        } while (Locker::query()->where('code', $code)->exists());

        return $code;
    }
}
