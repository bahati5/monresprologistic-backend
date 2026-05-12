<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Hub extends Model
{
    use HasUuid;

    protected $fillable = ['code', 'name', 'latitude', 'longitude', 'sort_order'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
