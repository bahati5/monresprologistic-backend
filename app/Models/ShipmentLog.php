<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentLog extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'shipment_id',
        'user_id',
        'status',
        'title',
        'description',
        'meta',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
