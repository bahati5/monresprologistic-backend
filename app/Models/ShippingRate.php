<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ShippingRate extends Model
{
    protected $fillable = [
        'agency_id', 'origin_country_id', 'dest_country_id',
        'shipping_mode_id', 'pricing_type', 'unit_price',
        'currency', 'weight_tiers', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'weight_tiers' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsToMany<Agency, ShippingRate> */
    public function agencies(): BelongsToMany
    {
        return $this->belongsToMany(Agency::class, 'shipping_rate_agency')->withTimestamps();
    }

    public function originCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'origin_country_id');
    }

    public function destCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'dest_country_id');
    }

    public function shippingMode(): BelongsTo
    {
        return $this->belongsTo(ShippingMode::class);
    }

    /** @return BelongsToMany<ShippingMode, ShippingRate> */
    public function shippingModes(): BelongsToMany
    {
        return $this->belongsToMany(ShippingMode::class, 'shipping_rate_shipping_mode')
            ->withTimestamps();
    }

    /** Pays d'origine (pivot scope=origin), complété par origin_country_id legacy. */
    public function originCountries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'shipping_rate_country')
            ->wherePivot('scope', 'origin')
            ->withTimestamps();
    }

    /** Pays de destination (pivot scope=destination), complété par dest_country_id legacy. */
    public function destinationCountries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'shipping_rate_country')
            ->wherePivot('scope', 'destination')
            ->withTimestamps();
    }

}
