<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceExtraLine extends Model
{
    protected $fillable = [
        'invoice_id', 'billing_extra_id', 'label', 'calculation_description',
        'type', 'value', 'amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function billingExtra(): BelongsTo
    {
        return $this->belongsTo(BillingExtra::class);
    }
}
