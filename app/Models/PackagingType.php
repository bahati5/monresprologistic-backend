<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackagingType extends Model
{
    protected $fillable = ['name', 'description', 'is_active', 'sort_order', 'is_billable', 'unit_price'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_billable' => 'boolean',
            'unit_price' => 'decimal:2',
        ];
    }
}
