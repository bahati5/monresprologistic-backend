<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'slug',
        'default_currency',
        'exchange_rates',
        'is_active',
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

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
