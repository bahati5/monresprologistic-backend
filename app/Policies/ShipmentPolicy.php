<?php

namespace App\Policies;

use App\Models\CrmClient;
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
            $crmId = CrmClient::query()->where('user_id', $user->id)->value('id');

            return $shipment->sender_id === $user->id
                || $shipment->recipient_id === $user->id
                || ($crmId && (int) $shipment->sender_client_id === (int) $crmId);
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
