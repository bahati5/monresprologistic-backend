<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPackage extends Model
{
    protected $fillable = [
        'reference_code',
        'user_id',
        'agency_id',
        'locker_id',
        'pre_alert_id',
        'status_id',
        'description',
        'merchant_name',
        'weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
        'declared_value',
        'value_currency',
        'shipping_cost',
        'total_cost',
        'received_at',
        'received_by',
        'condition_notes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'declared_value' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public static function generateReferenceCode(): string
    {
        $last = static::query()->orderByDesc('id')->value('id') ?? 0;

        return 'PKG-'.str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(Locker::class);
    }

    public function preAlert(): BelongsTo
    {
        return $this->belongsTo(PreAlert::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
