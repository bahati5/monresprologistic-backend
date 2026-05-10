<?php

namespace App\Policies;

use App\Models\Pickup;
use App\Models\User;

class PickupPolicy
{
    public function create(User $user): bool
    {
        return ! $user->hasAnyRole(['driver', 'client']);
    }

    public function update(User $user, Pickup $pickup): bool
    {
        if ($user->hasRole('driver')) {
            return (int) $pickup->assigned_driver_id === (int) $user->id;
        }

        if ($user->hasRole('client')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($user->canAccessAllAgencies()) {
            return true;
        }

        return (int) $pickup->agency_id === (int) ($user->agency_id ?? 0)
            && $user->hasAnyRole(['agency_admin', 'operator']);
    }
}
