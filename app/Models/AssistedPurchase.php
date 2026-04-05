<?php

namespace App\Models;

use App\Enums\AssistedPurchaseStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistedPurchase extends Model
{
    protected $fillable = [
        'user_id', 'status', 'operator_id', 'product_url', 'article_label', 'line_notes', 'notes', 'size', 'color', 'quantity',
        'price_displayed', 'price_currency', 'quote_amount', 'quote_currency', 'service_fee', 'bank_fee_percentage', 'payment_methods_note', 'supplier_tracking_number', 'total_amount',
        'commission_breakdown', 'quoted_at', 'paid_at', 'purchased_at', 'converted_pre_alert_id',
    ];

    protected $appends = ['status_label', 'status_color'];

    protected function casts(): array
    {
        return [
            'status' => AssistedPurchaseStatus::class,
            'commission_breakdown' => 'array',
            'service_fee' => 'decimal:2',
            'bank_fee_percentage' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'quoted_at' => 'datetime',
            'paid_at' => 'datetime',
            'purchased_at' => 'datetime',
        ];
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(function () {
            $s = $this->status;

            return $s instanceof AssistedPurchaseStatus ? $s->label() : '';
        });
    }

    protected function statusColor(): Attribute
    {
        return Attribute::get(function () {
            $s = $this->status;

            return $s instanceof AssistedPurchaseStatus ? $s->color() : '';
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssistedPurchaseItem::class);
    }

    public function convertedPreAlert(): BelongsTo
    {
        return $this->belongsTo(PreAlert::class, 'converted_pre_alert_id');
    }
}
