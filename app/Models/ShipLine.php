<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipLine extends Model
{
    use HasUuid;

    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function countryScopes(): HasMany
    {
        return $this->hasMany(ShipLineCountry::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ShipLineRate::class);
    }
}
