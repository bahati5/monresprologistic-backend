<?php

namespace App\Models;

use App\Enums\AssistedPurchaseStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AssistedPurchase extends Model
{
    protected $fillable = [
        'user_id', 'status', 'operator_id', 'product_url', 'article_label', 'line_notes', 'notes', 'size', 'color', 'quantity',
        'price_displayed', 'price_currency', 'quote_amount', 'quote_currency', 'service_fee', 'bank_fee_percentage', 'payment_methods_note', 'supplier_tracking_number', 'total_amount',
        'commission_breakdown', 'quoted_at', 'paid_at', 'purchased_at', 'converted_pre_alert_id', 'converted_shipment_id',
        'payment_proof_path',
        'estimated_weight_kg', 'hub_received_weight_kg', 'hub_received_photo_path',
        'is_urgent', 'quote_version', 'quote_expires_at', 'reminder_count', 'last_reminder_at',
        'refusal_reason', 'refusal_note', 'clarification_message', 'clarification_sent_at',
    ];

    protected $appends = ['status_label', 'status_color', 'payment_proof_url'];

    protected function casts(): array
    {
        return [
            'status' => AssistedPurchaseStatus::class,
            'commission_breakdown' => 'array',
            'service_fee' => 'decimal:2',
            'bank_fee_percentage' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'quoted_at' => 'datetime',
            'paid_at' => 'datetime',
            'purchased_at' => 'datetime',
            'estimated_weight_kg' => 'decimal:3',
            'hub_received_weight_kg' => 'decimal:3',
            'is_urgent' => 'boolean',
            'quote_version' => 'integer',
            'quote_expires_at' => 'datetime',
            'reminder_count' => 'integer',
            'last_reminder_at' => 'datetime',
            'clarification_sent_at' => 'datetime',
        ];
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(function () {
            $s = $this->status;

            return $s instanceof AssistedPurchaseStatus ? $s->label() : '';
        });
    }

    protected function statusColor(): Attribute
    {
        return Attribute::get(function () {
            $s = $this->status;

            return $s instanceof AssistedPurchaseStatus ? $s->color() : '';
        });
    }

    protected function paymentProofUrl(): Attribute
    {
        return Attribute::get(function () {
            $path = $this->payment_proof_path;
            if (! $path || trim($path) === '') {
                return null;
            }

            return url('/api/assisted-purchases/'.$this->id.'/payment-proof');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssistedPurchaseItem::class);
    }

    public function convertedPreAlert(): BelongsTo
    {
        return $this->belongsTo(PreAlert::class, 'converted_pre_alert_id');
    }

    public function convertedShipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'converted_shipment_id');
    }

    public static function generateReferenceCode(): string
    {
        $prefix = 'AP-';
        $code = $prefix . strtoupper(\Illuminate\Support\Str::random(8));

        while (static::where('line_notes', 'like', '%"reference_code":"' . $code . '"%')->exists()) {
            $code = $prefix . strtoupper(\Illuminate\Support\Str::random(8));
        }

        return $code;
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(QuoteSnapshot::class)->orderByDesc('version');
    }

    public function latestSnapshot()
    {
        return $this->hasOne(QuoteSnapshot::class)->latestOfMany('version');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AssistedPurchasePayment::class)->orderByDesc('id');
    }
}
