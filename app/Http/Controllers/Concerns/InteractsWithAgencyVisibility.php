<?php

namespace App\Http\Controllers\Concerns;

use App\Models\CrmClient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait InteractsWithAgencyVisibility
{
    protected function scopeShipmentsFor(Builder $query, User $user): Builder
    {
        if ($user->hasRole('client')) {
            $crmId = CrmClient::query()->where('user_id', $user->id)->value('id');

            return $query->where(function (Builder $q) use ($user, $crmId) {
                $q->where('sender_id', $user->id)
                    ->orWhere('recipient_id', $user->id);
                if ($crmId) {
                    $q->orWhere('sender_client_id', $crmId);
                }
            });
        }

        if (! $user->canAccessAllAgencies()) {
            return $query->where('agency_id', $user->agency_id);
        }

        return $query;
    }

    protected function scopeByAgency(Builder $query, User $user, string $column = 'agency_id'): Builder
    {
        if (! $user->canAccessAllAgencies()) {
            return $query->where($column, $user->agency_id);
        }

        return $query;
    }
}
