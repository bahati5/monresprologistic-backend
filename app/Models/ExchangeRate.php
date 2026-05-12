<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    use HasUuid;

    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'set_by',
        'valid_from',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'valid_from' => 'datetime',
        ];
    }

    public function setByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /**
     * Get the current rate for a currency pair.
     */
    public static function currentRate(string $from, string $to = 'USD'): ?float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return 1.0;
        }

        $record = static::currentRecord($from, $to);

        return $record?->rate !== null ? (float) $record->rate : null;
    }

    /**
     * Dernière ligne de taux applicable (pour affichage date / traçabilité PDF).
     */
    public static function currentRecord(string $from, string $to = 'USD'): ?self
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return null;
        }

        return static::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('valid_from', '<=', now())
            ->orderByDesc('valid_from')
            ->first();
    }
}
