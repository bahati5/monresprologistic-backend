<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PickupFailureReason extends Model
{
    use HasUuid;

    protected $fillable = [
        'agency_id',
        'label',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function pickups(): HasMany
    {
        return $this->hasMany(Pickup::class, 'failure_reason_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUserAgency($query, ?int $agencyId)
    {
        return $query->where('is_active', true)
            ->where(function ($q) use ($agencyId) {
                $q->whereNull('agency_id');
                if ($agencyId !== null && $agencyId > 0) {
                    $q->orWhere('agency_id', $agencyId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('label');
    }
}
