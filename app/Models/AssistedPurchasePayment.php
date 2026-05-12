<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistedPurchasePayment extends Model
{
    use HasUuid;

    protected $fillable = [
        'assisted_purchase_id',
        'amount',
        'currency',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function assistedPurchase(): BelongsTo
    {
        return $this->belongsTo(AssistedPurchase::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
