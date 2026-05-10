<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = [
        'slug', 'event_key', 'title', 'channel', 'subject',
        'body', 'channels', 'sample_variables', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sample_variables' => 'array',
            'channels' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
