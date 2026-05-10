<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncError extends Model
{
    protected $fillable = [
        'integration',
        'event_type',
        'entity_type',
        'entity_id',
        'payload',
        'error_message',
        'stack_trace',
        'attempt',
        'max_attempts',
        'resolved',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'resolved' => 'boolean',
            'next_retry_at' => 'datetime',
        ];
    }

    public function scopeUnresolved($query)
    {
        return $query->where('resolved', false);
    }

    public function scopeForIntegration($query, string $integration)
    {
        return $query->where('integration', $integration);
    }

    public function markResolved(): void
    {
        $this->update(['resolved' => true]);
    }
}
