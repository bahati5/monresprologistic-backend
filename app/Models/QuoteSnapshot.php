<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteSnapshot extends Model
{
    use HasUuid;

    protected $fillable = [
        'assisted_purchase_id',
        'version',
        'snapshot_data',
        'articles_data',
        'total_primary',
        'total_secondary',
        'primary_currency',
        'secondary_currency',
        'exchange_rate_used',
        'revision_reason',
        'created_by',
        'is_urgent',
        'urgency_surcharge_percent',
        'estimated_delivery',
        'staff_message',
        'sent_at',
        'expires_at',
        'response_token',
        'client_response',
        'refusal_reason',
        'refusal_note',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_data' => 'array',
            'articles_data' => 'array',
            'total_primary' => 'decimal:2',
            'total_secondary' => 'decimal:0',
            'exchange_rate_used' => 'decimal:4',
            'is_urgent' => 'boolean',
            'urgency_surcharge_percent' => 'decimal:2',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function assistedPurchase(): BelongsTo
    {
        return $this->belongsTo(AssistedPurchase::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->client_response === 'pending' && ! $this->isExpired();
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('version');
    }

    public function scopePending($query)
    {
        return $query->where('client_response', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
