<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $fillable = [
        'public_tracking',
        'invoice_document_number',
        'sender_profile_id',
        'recipient_profile_id',
        'creator_user_id',
        'agency_id',
        'origin_country_id',
        'dest_country_id',
        'status',
        'service_flow',
        'regroupement_id',
        'master_shipment_id',
        'pre_alert_id',
        'assisted_purchase_id',
        'assigned_driver_id',
        'weight_kg',
        'volumetric_weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
        'declared_value',
        'company_coverage_amount',
        'declared_currency',
        'service_options',
        'pricing_snapshot',
        'calculated_price',
        'currency',
        'current_hub_id',
        'delivery_signature',
        'delivery_notes',
        'delivery_proof_path',
        'payment_status',
        'amount_paid',
        'paid_at',
        'signed_form_path',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'service_options' => 'array',
            'pricing_snapshot' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    /** Pour KPI / CA / rapports : les brouillons ne sont pas des expéditions « réelles ». */
    public function scopeExcludingDrafts(Builder $query): Builder
    {
        return $query->whereNot($query->qualifyColumn('status'), ShipmentStatus::Draft);
    }

    // ─── Relationships ───────────────────────────────

    public function senderProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'sender_profile_id');
    }

    public function recipientProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'recipient_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function originCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'origin_country_id');
    }

    public function destCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'dest_country_id');
    }

    public function regroupement(): BelongsTo
    {
        return $this->belongsTo(Regroupement::class, 'regroupement_id');
    }

    public function preAlert(): BelongsTo
    {
        return $this->belongsTo(PreAlert::class);
    }

    public function assistedPurchase(): BelongsTo
    {
        return $this->belongsTo(AssistedPurchase::class);
    }

    public function currentHub(): BelongsTo
    {
        return $this->belongsTo(Hub::class, 'current_hub_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ShipmentLog::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ShipmentPayment::class)->orderByDesc('created_at');
    }
}
