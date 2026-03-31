<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'reference_code',
        'user_id',
        'agency_id',
        'operator_id',
        'status_id',
        'cart_url',
        'quote_amount',
        'quote_currency',
        'commission_amount',
        'local_shipping_fee',
        'total_amount',
        'quoted_at',
        'paid_at',
        'purchased_at',
        'received_at',
        'converted_customer_package_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quote_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'local_shipping_fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'quoted_at' => 'datetime',
            'paid_at' => 'datetime',
            'purchased_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public static function generateReferenceCode(): string
    {
        $last = static::query()->orderByDesc('id')->value('id') ?? 0;

        return 'PO-'.str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function customerPackage(): BelongsTo
    {
        return $this->belongsTo(CustomerPackage::class, 'converted_customer_package_id');
    }
}
