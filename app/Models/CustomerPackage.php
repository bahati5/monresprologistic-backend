<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use App\Models\Concerns\HasUuid;
use App\Support\ConfigurableReferenceCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPackage extends Model
{
    use HasUuid;

    protected $fillable = [
        'reference_code',
        'user_id',
        'agency_id',
        'locker_id',
        'pre_alert_id',
        'status',
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
            'status' => ShipmentStatus::class,
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
        return ConfigurableReferenceCode::allocate(
            'customer_package_reference_format',
            '{prefix}-{seq}',
            'customer_package_reference_prefix',
            'PKG',
            'customer_package_reference_seq_pad',
            4,
            'customer_package_next_seq',
            static::query(),
        );
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

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
