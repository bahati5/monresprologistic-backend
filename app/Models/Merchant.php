<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    use HasUuid;

    protected $fillable = [
        'name',
        'domains',
        'logo_url',
        'commission_rate',
        'estimated_delivery_days',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'commission_rate' => 'decimal:2',
            'estimated_delivery_days' => 'integer',
        ];
    }

    public function assistedPurchaseItems(): HasMany
    {
        return $this->hasMany(AssistedPurchaseItem::class);
    }
}
