<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_shipments');
    }

    public function view(User $user, Shipment $shipment): bool
    {
        if ($user->hasRole('client')) {
            $profileId = $user->profile_id;

            return (int) $shipment->creator_user_id === (int) $user->id
                || ($profileId && (int) $shipment->sender_profile_id === (int) $profileId);
        }

        if (! $user->can('view_shipments')) {
            return false;
        }

        if ($user->canAccessAllAgencies()) {
            return true;
        }

        return $shipment->agency_id === $user->agency_id;
    }

    public function create(User $user): bool
    {
        return $user->can('create_shipments');
    }

    public function update(User $user, Shipment $shipment): bool
    {
        if (! $user->can('edit_shipments')) {
            return false;
        }

        if ($user->canAccessAllAgencies()) {
            return true;
        }

        return $shipment->agency_id === $user->agency_id;
    }
}
