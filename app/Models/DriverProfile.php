<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverProfile extends Model
{
    protected $fillable = [
        'user_id',
        'license_number',
        'license_expiry',
        'vehicle_type',
        'vehicle_plate',
        'vehicle_brand',
        'coverage_zone',
        'emergency_contact',
        'emergency_phone',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'license_expiry' => 'date',
            'is_available' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
