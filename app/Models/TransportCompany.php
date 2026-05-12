<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class TransportCompany extends Model
{
    use HasUuid;

    protected $fillable = [
        'name', 'contact_name', 'contact_email', 'contact_phone', 'logo_path', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
