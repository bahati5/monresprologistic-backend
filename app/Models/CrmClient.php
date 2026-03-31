<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CrmClient extends Model
{
    protected $table = 'crm_clients';

    protected $appends = [
        'display_name',
    ];

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_mobile',
        'agency_id',
        'billing_address',
        'billing_postal_code',
        'billing_country_id',
        'billing_state_id',
        'billing_city_id',
        'user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function billingCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'billing_country_id');
    }

    public function billingState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'billing_state_id');
    }

    public function billingCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'billing_city_id');
    }

    public function locker(): HasOne
    {
        return $this->hasOne(Locker::class, 'crm_client_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class, 'crm_client_id');
    }

    public function hasPortalAccess(): bool
    {
        return $this->user_id !== null;
    }
}
