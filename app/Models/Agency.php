<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'code',
        'name',
        'slug',
        'default_currency',
        'exchange_rates',
        'is_active',
        'contact_phone',
        'contact_phone_secondary',
        'contact_email',
        'address',
        'country_id',
        'state_id',
        'city_id',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rates' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
