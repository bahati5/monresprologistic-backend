<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Status extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'code',
        'name',
        'color_hex',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function transitionsFrom(): HasMany
    {
        return $this->hasMany(StatusTransition::class, 'from_status_id');
    }
}
