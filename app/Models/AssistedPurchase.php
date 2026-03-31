<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistedPurchase extends Model
{
    protected $fillable = [
        'user_id', 'status_id', 'operator_id', 'product_url', 'size', 'color', 'quantity',
        'price_displayed', 'price_currency', 'quote_amount', 'quote_currency',
        'commission_breakdown', 'quoted_at', 'paid_at', 'purchased_at', 'converted_pre_alert_id',
    ];

    protected function casts(): array
    {
        return [
            'commission_breakdown' => 'array',
            'quoted_at' => 'datetime',
            'paid_at' => 'datetime',
            'purchased_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function convertedPreAlert(): BelongsTo
    {
        return $this->belongsTo(PreAlert::class, 'converted_pre_alert_id');
    }
}
