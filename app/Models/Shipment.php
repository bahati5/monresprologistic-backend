<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $fillable = [
        'public_tracking',
        'sender_id',
        'sender_client_id',
        'recipient_id',
        'delivery_recipient_id',
        'agency_id',
        'status_id',
        'service_type_id',
        'consolidation_id',
        'master_shipment_id',
        'pre_alert_id',
        'assigned_driver_id',
        'weight_kg',
        'volumetric_weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
        'declared_value',
        'declared_currency',
        'service_options',
        'pricing_snapshot',
        'calculated_price',
        'currency',
        'current_hub_id',
        'delivery_signature',
        'delivery_notes',
        'payment_status',
        'amount_paid',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'service_options' => 'array',
            'pricing_snapshot' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ShipmentLog::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function senderClient(): BelongsTo
    {
        return $this->belongsTo(CrmClient::class, 'sender_client_id');
    }

    /**
     * Utilisateur expéditeur (si compte portail). Peut être null.
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * Fiche destinataire (carnet d’adresses).
     */
    public function deliveryRecipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class, 'delivery_recipient_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function consolidation(): BelongsTo
    {
        return $this->belongsTo(Consolidation::class);
    }

    public function preAlert(): BelongsTo
    {
        return $this->belongsTo(PreAlert::class);
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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
