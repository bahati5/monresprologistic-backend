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
        if ($user->hasRole('client')) {
            // Les clients ne peuvent plus modifier directement les expéditions.
            // Les modifications passent par le système de brouillons (form_drafts).
            return false;
        }

        if ($user->hasRole('driver')
            && (int) ($shipment->assigned_driver_id ?? 0) === (int) $user->id
            && $user->can('view_shipments')) {
            return (int) ($shipment->agency_id ?? 0) === (int) ($user->agency_id ?? 0);
        }

        if (! $user->can('edit_shipments')) {
            return false;
        }

        if ($user->canAccessAllAgencies()) {
            return true;
        }

        return $shipment->agency_id === $user->agency_id;
    }
}
