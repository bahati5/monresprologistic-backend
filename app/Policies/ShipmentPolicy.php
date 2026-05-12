<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

class ShipmentPolicy
{
    /** @deprecated noms historiques Spatie (view_shipments) + format module (shipments.view) */
    private function canViewShipments(User $user): bool
    {
        return $user->can('shipments.view') || $user->can('view_shipments');
    }

    private function canCreateShipments(User $user): bool
    {
        return $user->can('shipments.create') || $user->can('create_shipments');
    }

    private function canEditShipments(User $user): bool
    {
        return $user->can('shipments.edit') || $user->can('edit_shipments');
    }

    public function viewAny(User $user): bool
    {
        return $this->canViewShipments($user);
    }

    public function view(User $user, Shipment $shipment): bool
    {
        if ($user->hasRole('client')) {
            $profileId = $user->profile_id;

            return (int) $shipment->creator_user_id === (int) $user->id
                || ($profileId && (int) $shipment->sender_profile_id === (int) $profileId);
        }

        if ($user->hasRole('driver')) {
            return (int) ($shipment->assigned_driver_id ?? 0) === (int) $user->id
                && (int) ($shipment->agency_id ?? 0) === (int) ($user->agency_id ?? 0);
        }

        if (! $this->canViewShipments($user)) {
            return false;
        }

        if ($user->canAccessAllAgencies()) {
            return true;
        }

        return $shipment->agency_id === $user->agency_id;
    }

    public function create(User $user): bool
    {
        if ($user->hasRole('client')) {
            return false;
        }

        return $this->canCreateShipments($user);
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
            && $this->canViewShipments($user)) {
            return (int) ($shipment->agency_id ?? 0) === (int) ($user->agency_id ?? 0);
        }

        if (! $this->canEditShipments($user)) {
            return false;
        }

        if ($user->canAccessAllAgencies()) {
            return true;
        }

        return $shipment->agency_id === $user->agency_id;
    }
}
