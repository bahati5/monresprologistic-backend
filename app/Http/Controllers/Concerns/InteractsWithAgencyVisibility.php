<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait InteractsWithAgencyVisibility
{
    protected function scopeShipmentsForUser(Builder $query, User $user): Builder
    {
        if ($user->hasRole('client')) {
            $profileId = $user->profile_id;

            return $query->where(function (Builder $q) use ($profileId, $user) {
                $q->where('creator_user_id', $user->id);
                if ($profileId) {
                    $q->orWhere('sender_profile_id', $profileId);
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
