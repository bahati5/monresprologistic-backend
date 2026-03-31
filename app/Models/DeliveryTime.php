<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryTime extends Model
{
    protected $fillable = ['label', 'description', 'shipping_mode_id', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function shippingMode(): BelongsTo
    {
        return $this->belongsTo(ShippingMode::class);
    }
}
