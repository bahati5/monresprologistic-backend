<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingMode extends Model
{
    protected $fillable = ['name', 'description', 'is_active', 'sort_order', 'volumetric_divisor', 'default_pricing_type', 'delivery_options'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'volumetric_divisor' => 'integer',
            'delivery_options' => 'array',
        ];
    }
}
