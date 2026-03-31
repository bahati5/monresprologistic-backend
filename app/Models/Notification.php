<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'channel',
        'title',
        'body',
        'data',
        'action_url',
        'read_at',
        'sent_at',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsRead(): void
    {
        if (! $this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    public function markAsSent(): void
    {
        $this->update(['sent_at' => now(), 'status' => 'sent']);
    }

    public function markAsFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error_message' => $error]);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeInApp($query)
    {
        return $query->where('type', 'in_app');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
