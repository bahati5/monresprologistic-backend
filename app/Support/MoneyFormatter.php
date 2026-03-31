<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Formatage monétaire aligné sur les paramètres application (Settings > Devise).
 */
class MoneyFormatter
{
    public static function formatAmount(float $amount): string
    {
        $decimals = max(0, min(4, (int) (Setting::getValue('decimals', '2') ?: 2)));
        $symbol = (string) (Setting::getValue('currency_symbol', '€') ?: '€');
        $position = (string) (Setting::getValue('symbol_position', 'prefix') ?: 'prefix');
        $formatted = number_format($amount, $decimals, ',', "\u{00a0}");

        return $position === 'suffix' ? $formatted."\u{00a0}".$symbol : $symbol.$formatted;
    }

    public static function currencyCode(): string
    {
        return (string) (Setting::getValue('currency', 'EUR') ?: 'EUR');
    }
}
