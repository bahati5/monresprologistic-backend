<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $fillable = ['client_wallet_id', 'reference', 'amount', 'type', 'meta'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'amount' => 'decimal:2',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(ClientWallet::class, 'client_wallet_id');
    }
}
