<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $fillable = [
        'name', 'code', 'iso2', 'iso3', 'numeric_code', 'phonecode',
        'capital', 'currency', 'currency_name', 'currency_symbol',
        'native', 'nationality', 'latitude', 'longitude',
        'emoji', 'emojiU', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }
}
