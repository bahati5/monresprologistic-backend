<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    use HasUuid;

    protected $fillable = ['code', 'name', 'description', 'icon', 'order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function elements(): HasMany
    {
        return $this->hasMany(FrontendElement::class);
    }

    public function activeElements(): HasMany
    {
        return $this->hasMany(FrontendElement::class)->where('is_active', true);
    }
}
