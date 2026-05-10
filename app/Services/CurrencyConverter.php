<?php

namespace App\Services;

use App\Models\ExchangeRate;

class CurrencyConverter
{
    public static function convert(float $amount, string $from, string $to = 'USD'): ?array
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return [
                'original_amount' => $amount,
                'original_currency' => $from,
                'converted_amount' => $amount,
                'target_currency' => $to,
                'rate' => 1.0,
                'rate_date' => now()->toDateTimeString(),
            ];
        }

        $rateRecord = ExchangeRate::currentRecord($from, $to);
        $rate = $rateRecord?->rate !== null ? (float) $rateRecord->rate : null;

        if ($rate === null || $rate <= 0) {
            return null;
        }

        return [
            'original_amount' => $amount,
            'original_currency' => $from,
            'converted_amount' => round($amount * $rate, 2),
            'target_currency' => $to,
            'rate' => $rate,
            'rate_date' => $rateRecord?->valid_from?->toDateTimeString(),
        ];
    }
}
