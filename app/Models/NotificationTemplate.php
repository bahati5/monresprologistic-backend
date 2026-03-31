<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = ['slug', 'channel', 'subject', 'body', 'sample_variables', 'is_active'];

    protected function casts(): array
    {
        return [
            'sample_variables' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
