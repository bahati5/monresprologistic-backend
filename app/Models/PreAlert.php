<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use App\Support\ConfigurableReferenceCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PreAlert extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'reference_code',
        'user_id',
        'locker_id',
        'status',
        'merchant_name',
        'vendor_tracking_number',
        'carrier_name',
        'notes',
        'description',
        'declared_value',
        'declared_weight_kg',
        'value_currency',
        'purchase_date',
        'estimated_arrival_date',
        'converted_shipment_id',
        'converted_customer_package_id',
        'issue_reported',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'declared_value' => 'decimal:2',
            'declared_weight_kg' => 'decimal:3',
            'purchase_date' => 'date',
            'estimated_arrival_date' => 'date',
            'issue_reported' => 'boolean',
        ];
    }

    public static function generateReferenceCode(): string
    {
        return ConfigurableReferenceCode::allocate(
            'prealert_reference_format',
            '{prefix}-{seq}',
            'prealert_reference_prefix',
            'ASN',
            'prealert_reference_seq_pad',
            4,
            'prealert_next_seq',
            static::query(),
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(Locker::class);
    }

    public function customerPackage(): HasOne
    {
        return $this->hasOne(CustomerPackage::class, 'pre_alert_id');
    }

    public function issueReports(): HasMany
    {
        return $this->hasMany(PreAlertIssueReport::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf']);
    }

    /**
     * Avis encore à traiter en réception (hors colis déjà créé / statut réceptionné).
     */
    public function scopeActionableInboundQueue(Builder $query): Builder
    {
        return $query
            ->whereNull('converted_customer_package_id')
            ->whereNotIn('status', [
                ShipmentStatus::ReceivedAtHub->value,
                ShipmentStatus::Cancelled->value,
                ShipmentStatus::Expired->value,
            ]);
    }
}
