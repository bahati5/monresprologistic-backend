<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipient extends Model
{
    protected $fillable = [
        'crm_client_id',
        'user_id',
        'name',
        'email',
        'phone',
        'phone_secondary',
        'address',
        'landmark',
        'city',
        'state',
        'country',
        'country_id',
        'state_id',
        'city_id',
        'zip_code',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function crmClient(): BelongsTo
    {
        return $this->belongsTo(CrmClient::class, 'crm_client_id');
    }

    public function countryModel(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function stateModel(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function cityModel(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(RecipientAddress::class);
    }
}
