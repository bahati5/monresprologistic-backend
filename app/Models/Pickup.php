<?php

namespace App\Models;

use App\Enums\PickupStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pickup extends Model
{
    protected $fillable = [
        'user_id', 'agency_id', 'shipment_id', 'status', 'assigned_driver_id',
        'latitude', 'longitude', 'address_text', 'requested_window', 'completed_at',
        'completion_photo_path', 'completion_notes', 'failure_reason', 'failure_reason_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PickupStatus::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function failureReason(): BelongsTo
    {
        return $this->belongsTo(PickupFailureReason::class, 'failure_reason_id');
    }
}
