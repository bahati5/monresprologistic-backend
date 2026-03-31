<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMode extends Model
{
    protected $fillable = ['name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function deliveryTimes(): HasMany
    {
        return $this->hasMany(DeliveryTime::class)->orderBy('sort_order');
    }
}
