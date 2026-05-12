<?php

namespace App\Services;

use App\Models\QuoteLineTemplate;
use App\Models\Setting;

class QuoteCalculationService
{
    /**
     * Calculate quote totals from articles and dynamic lines.
     *
     * @param array $articles [{unit_price, quantity, ...}]
     * @param array $activeLines [{internal_code, type, calculation_base, value, ...}]
     * @return array {subtotal, lines_breakdown, total_primary, total_secondary, exchange_rate}
     */
    public function calculate(array $articles, array $activeLines): array
    {
        $subtotal = $this->computeSubtotal($articles);
        $linesBreakdown = [];
        $linesTotal = 0;

        $commissionAmount = 0;
        foreach ($activeLines as $line) {
            if (($line['internal_code'] ?? '') === 'COMMISSION') {
                $commissionAmount = $this->computeLineAmount($line, $subtotal, 0);
                break;
            }
        }

        $subtotalAfterCommission = $subtotal + $commissionAmount;

        foreach ($activeLines as $line) {
            $base = $this->resolveBase($line, $subtotal, $subtotalAfterCommission);
            $amount = $this->computeLineAmount($line, $base, $subtotalAfterCommission);

            $linesBreakdown[] = [
                'internal_code' => $line['internal_code'] ?? '',
                'name' => $line['name'] ?? '',
                'type' => $line['type'],
                'value' => (float) ($line['value'] ?? 0),
                'amount' => round($amount, 2),
                'is_visible_to_client' => $line['is_visible_to_client'] ?? true,
            ];

            $linesTotal += $amount;
        }

        $totalPrimary = round($subtotal + $linesTotal, 2);

        $currencySettings = $this->getCurrencySettings();
        $totalSecondary = null;
        $exchangeRate = null;

        if ($currencySettings['enabled']) {
            $exchangeRate = $currencySettings['rate'];
            $totalSecondary = round($totalPrimary * $exchangeRate, 0);
        }

        return [
            'subtotal' => round($subtotal, 2),
            'lines_breakdown' => $linesBreakdown,
            'lines_total' => round($linesTotal, 2),
            'total_primary' => $totalPrimary,
            'total_secondary' => $totalSecondary,
            'primary_currency' => $currencySettings['primary'],
            'secondary_currency' => $currencySettings['enabled'] ? $currencySettings['secondary'] : null,
            'exchange_rate' => $exchangeRate,
        ];
    }

    public function buildSnapshot(
        array $articles,
        array $activeLines,
        array $calculationResult,
        ?string $estimatedDelivery,
        ?string $staffMessage,
        bool $isUrgent,
        ?float $urgencySurchargePercent,
    ): array {
        $finalTotal = $calculationResult['total_primary'];

        if ($isUrgent && $urgencySurchargePercent > 0) {
            $surcharge = round($finalTotal * ($urgencySurchargePercent / 100), 2);
            $finalTotal += $surcharge;
        }

        return [
            'articles' => $articles,
            'lines' => $calculationResult['lines_breakdown'],
            'configuration_lines' => array_values(array_map(static function (array $line): array {
                return [
                    'internal_code' => (string) ($line['internal_code'] ?? ''),
                    'name' => (string) ($line['name'] ?? ''),
                    'type' => (string) ($line['type'] ?? 'manual'),
                    'calculation_base' => $line['calculation_base'] ?? null,
                    'value' => (float) ($line['value'] ?? 0),
                    'is_visible_to_client' => (bool) ($line['is_visible_to_client'] ?? true),
                ];
            }, $activeLines)),
            'subtotal' => $calculationResult['subtotal'],
            'lines_total' => $calculationResult['lines_total'],
            'total_primary' => round($finalTotal, 2),
            'total_secondary' => $calculationResult['secondary_currency']
                ? round($finalTotal * $calculationResult['exchange_rate'], 0)
                : null,
            'primary_currency' => $calculationResult['primary_currency'],
            'secondary_currency' => $calculationResult['secondary_currency'],
            'exchange_rate' => $calculationResult['exchange_rate'],
            'is_urgent' => $isUrgent,
            'urgency_surcharge_percent' => $urgencySurchargePercent,
            'estimated_delivery' => $estimatedDelivery,
            'staff_message' => $staffMessage,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function computeSubtotal(array $articles): float
    {
        $total = 0;
        foreach ($articles as $article) {
            $price = (float) ($article['unit_price'] ?? 0);
            $qty = (int) ($article['quantity'] ?? 1);
            $total += $price * $qty;
        }

        return $total;
    }

    private function resolveBase(array $line, float $subtotal, float $subtotalAfterCommission): float
    {
        if (($line['type'] ?? '') !== 'percentage') {
            return $subtotal;
        }

        $base = $line['calculation_base'] ?? 'product_price';

        return match ($base) {
            'subtotal_after_commission' => $subtotalAfterCommission,
            default => $subtotal,
        };
    }

    private function computeLineAmount(array $line, float $base, float $subtotalAfterCommission): float
    {
        $value = (float) ($line['value'] ?? 0);

        return match ($line['type'] ?? 'manual') {
            'percentage' => round($base * ($value / 100), 2),
            'fixed_amount' => $value,
            'manual' => $value,
            default => 0,
        };
    }

    protected function getCurrencySettings(): array
    {
        $settings = Setting::whereIn('key', [
            'quote_primary_currency',
            'quote_secondary_currency_enabled',
            'quote_secondary_currency',
            'quote_secondary_currency_rate',
        ])->pluck('value', 'key');

        return [
            'primary' => $settings['quote_primary_currency'] ?? 'USD',
            'enabled' => (bool) ($settings['quote_secondary_currency_enabled'] ?? false),
            'secondary' => $settings['quote_secondary_currency'] ?? 'CDF',
            'rate' => (float) ($settings['quote_secondary_currency_rate'] ?? 2800),
        ];
    }
}
