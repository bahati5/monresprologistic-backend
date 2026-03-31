<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Consolidation extends Model
{
    protected $fillable = [
        'master_tracking',
        'user_id',
        'agency_id',
        'status_id',
        'total_weight_kg',
        'total_volume_m3',
        'pricing_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'pricing_snapshot' => 'array',
            'total_weight_kg' => 'decimal:3',
            'total_volume_m3' => 'decimal:6',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function shipments(): BelongsToMany
    {
        return $this->belongsToMany(Shipment::class, 'consolidation_shipment')
            ->withTimestamps();
    }
}
