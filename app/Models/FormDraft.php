<?php

namespace App\Models;

use App\Enums\FormDraftType;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormDraft extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id',
        'form_type',
        'payload',
        'metadata',
        'last_saved_at',
        'expires_at',
        'agency_id',
    ];

    protected function casts(): array
    {
        return [
            'form_type' => FormDraftType::class,
            'payload' => 'array',
            'metadata' => 'array',
            'last_saved_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    // ─── Scopes ──────────────────────────────────────

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOfType(Builder $query, FormDraftType $type): Builder
    {
        return $query->where('form_type', $type->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeExpiringSoon(Builder $query, int $days = 3): Builder
    {
        return $query->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    // ─── Helpers ─────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    // ─── Relationships ───────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
