<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number', 'user_id', 'shipment_id', 'agency_id',
        'amount', 'base_amount', 'currency',
        'status', 'due_at', 'paid_at', 'odoo_exported_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'odoo_exported_at' => 'datetime',
            'base_amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    public function extraLines(): HasMany
    {
        return $this->hasMany(InvoiceExtraLine::class)->orderBy('sort_order');
    }
}
