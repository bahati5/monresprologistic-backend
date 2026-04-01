<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipLineRate extends Model
{
    protected $fillable = [
        'ship_line_id',
        'shipping_mode_id',
        'delivery_time_id',
        'unit_price',
        'currency',
        'pricing_type',
        'is_active',
        'volumetric_divisor',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'unit_price' => 'decimal:2',
        ];
    }

    public function shipLine(): BelongsTo
    {
        return $this->belongsTo(ShipLine::class);
    }

    public function shippingMode(): BelongsTo
    {
        return $this->belongsTo(ShippingMode::class);
    }

    public function deliveryTime(): BelongsTo
    {
        return $this->belongsTo(DeliveryTime::class);
    }

    public function computeBaseQuote(float $billableWeightKg, float $volumeM3): float
    {
        $price = (float) $this->unit_price;

        return match ($this->pricing_type) {
            'per_volume' => round($price * max($volumeM3, 0), 2),
            'flat' => round($price, 2),
            default => round($price * max($billableWeightKg, 0), 2),
        };
    }
}
