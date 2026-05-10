<?php

namespace App\Models;

use App\Enums\RefundStatus;
use App\Support\ConfigurableReferenceCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Refund extends Model
{
    protected $fillable = [
        'reference_code',
        'refundable_type',
        'refundable_id',
        'client_id',
        'agency_id',
        'amount',
        'currency',
        'status',
        'reason',
        'reason_category',
        'payment_method',
        'payment_details',
        'reviewed_by',
        'processed_by',
        'rejection_reason',
        'proof_path',
        'reviewed_at',
        'processed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'payment_details' => 'array',
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected $appends = ['status_label', 'status_color', 'has_request_proof'];

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn () => $this->status instanceof RefundStatus ? $this->status->label() : '');
    }

    protected function statusColor(): Attribute
    {
        return Attribute::get(fn () => $this->status instanceof RefundStatus ? $this->status->color() : '');
    }

    protected function hasRequestProof(): Attribute
    {
        return Attribute::get(function () {
            $path = $this->payment_details['request_proof_path'] ?? null;

            return is_string($path) && $path !== '';
        });
    }

    public static function generateReferenceCode(): string
    {
        return ConfigurableReferenceCode::allocate(
            'refund_reference_format',
            '{prefix}-{seq}',
            'refund_reference_prefix',
            'RMB',
            'refund_reference_seq_pad',
            4,
            'refund_next_seq',
            static::query(),
        );
    }

    public function refundable(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
