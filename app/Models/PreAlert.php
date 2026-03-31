<?php

namespace App\Models;

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
        'status_id',
        'merchant_name',
        'vendor_tracking_number',
        'carrier_name',
        'notes',
        'description',
        'declared_value',
        'value_currency',
        'purchase_date',
        'estimated_arrival_date',
        'converted_shipment_id',
        'converted_customer_package_id',
    ];

    protected function casts(): array
    {
        return [
            'declared_value' => 'decimal:2',
            'purchase_date' => 'date',
            'estimated_arrival_date' => 'date',
        ];
    }

    public static function generateReferenceCode(): string
    {
        $last = static::query()->orderByDesc('id')->value('id') ?? 0;

        return 'ASN-'.str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(Locker::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
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
            ->whereDoesntHave('status', fn (Builder $s) => $s->where('code', 'received_hub'));
    }
}
