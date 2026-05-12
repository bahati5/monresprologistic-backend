<?php

namespace Tests\Unit;

use App\Services\QuoteCalculationService;
use Tests\TestCase;

/**
 * Test double: deterministic currency settings without hitting the DB / settings cache.
 *
 * LDV-001 to LDV-009 — backend quote line engine ({@see QuoteCalculationService::calculate}, {@see QuoteCalculationService::buildSnapshot}).
 */
final class QuoteCalculationServiceHarness extends QuoteCalculationService
{
    public function __construct(private array $currencySettings) {}

    protected function getCurrencySettings(): array
    {
        return $this->currencySettings;
    }
}

class QuoteLineEngineTest extends TestCase
{
    private function service(bool $secondaryEnabled = false, float $rate = 2800.0): QuoteCalculationServiceHarness
    {
        return new QuoteCalculationServiceHarness([
            'primary' => 'USD',
            'enabled' => $secondaryEnabled,
            'secondary' => 'CDF',
            'rate' => $rate,
        ]);
    }

    /** LDV-001: Percentage line on product_price base */
    public function test_ldv001_percentage_on_product_price_base(): void
    {
        $service = $this->service();
        $articles = [['unit_price' => 200, 'quantity' => 1]];
        $lines = [
            [
                'internal_code' => 'MARGIN',
                'name' => 'Margin',
                'type' => 'percentage',
                'calculation_base' => 'product_price',
                'value' => 12,
                'is_visible_to_client' => true,
            ],
        ];

        $result = $service->calculate($articles, $lines);

        $this->assertSame(24.0, $result['lines_breakdown'][0]['amount']);
        $this->assertSame(224.0, $result['total_primary']);
    }

    /** LDV-002: Percentage line on subtotal_after_commission base */
    public function test_ldv002_percentage_on_subtotal_after_commission(): void
    {
        $service = $this->service();
        $articles = [['unit_price' => 100, 'quantity' => 1]];
        $lines = [
            [
                'internal_code' => 'COMMISSION',
                'name' => 'Commission',
                'type' => 'percentage',
                'calculation_base' => 'product_price',
                'value' => 10,
                'is_visible_to_client' => true,
            ],
            [
                'internal_code' => 'EXTRA',
                'name' => 'After commission fee',
                'type' => 'percentage',
                'calculation_base' => 'subtotal_after_commission',
                'value' => 5,
                'is_visible_to_client' => true,
            ],
        ];

        $result = $service->calculate($articles, $lines);

        $this->assertSame(100.0, $result['subtotal']);
        $this->assertSame(10.0, $result['lines_breakdown'][0]['amount']);
        $this->assertSame(5.50, $result['lines_breakdown'][1]['amount']);
        $this->assertSame(115.50, $result['total_primary']);
    }

    /** LDV-003: Fixed amount line */
    public function test_ldv003_fixed_amount_line(): void
    {
        $service = $this->service();
        $articles = [['unit_price' => 40, 'quantity' => 1]];
        $lines = [
            [
                'internal_code' => 'SHIPPING',
                'name' => 'Shipping',
                'type' => 'fixed_amount',
                'value' => 12.5,
                'is_visible_to_client' => true,
            ],
        ];

        $result = $service->calculate($articles, $lines);

        $this->assertSame(12.5, $result['lines_breakdown'][0]['amount']);
        $this->assertSame(52.50, $result['total_primary']);
    }

    /** LDV-004: Manual amount line */
    public function test_ldv004_manual_amount_line(): void
    {
        $service = $this->service();
        $articles = [['unit_price' => 75, 'quantity' => 1]];
        $lines = [
            [
                'internal_code' => 'ADJ',
                'name' => 'Manual adjustment',
                'type' => 'manual',
                'value' => 20,
                'is_visible_to_client' => false,
            ],
        ];

        $result = $service->calculate($articles, $lines);

        $this->assertSame(20.0, $result['lines_breakdown'][0]['amount']);
        $this->assertSame(95.0, $result['total_primary']);
    }

    /** LDV-005: Multiple lines accumulate correctly */
    public function test_ldv005_multiple_lines_accumulate(): void
    {
        $service = $this->service();
        $articles = [['unit_price' => 100, 'quantity' => 2]];
        $lines = [
            ['internal_code' => 'COMMISSION', 'name' => 'C', 'type' => 'percentage', 'calculation_base' => 'product_price', 'value' => 5, 'is_visible_to_client' => true],
            ['internal_code' => 'SHIP', 'name' => 'S', 'type' => 'fixed_amount', 'value' => 30, 'is_visible_to_client' => true],
            ['internal_code' => 'CUSTOM', 'name' => 'M', 'type' => 'manual', 'value' => 7.5, 'is_visible_to_client' => true],
        ];

        $result = $service->calculate($articles, $lines);

        $this->assertSame(247.50, $result['total_primary']);
        $this->assertSame(47.50, $result['lines_total']);
    }

    /** LDV-006: Urgency surcharge applies correctly (buildSnapshot) */
    public function test_ldv006_urgency_surcharge_applies(): void
    {
        $service = $this->service();
        $articles = [['unit_price' => 100, 'quantity' => 1]];
        $activeLines = [
            ['internal_code' => 'FEE', 'name' => 'Fee', 'type' => 'fixed_amount', 'value' => 50, 'is_visible_to_client' => true],
        ];

        $calc = $service->calculate($articles, $activeLines);
        $snapshot = $service->buildSnapshot(
            $articles,
            $activeLines,
            $calc,
            '3-5 days',
            null,
            true,
            20.0,
        );

        $this->assertTrue($snapshot['is_urgent']);
        $this->assertSame(180.0, $snapshot['total_primary']);
    }

    /** LDV-007: Secondary currency conversion */
    public function test_ldv007_secondary_currency_conversion(): void
    {
        $service = $this->service(secondaryEnabled: true, rate: 2500.0);
        $articles = [['unit_price' => 40, 'quantity' => 1]];
        $lines = [
            ['internal_code' => 'X', 'name' => 'X', 'type' => 'fixed_amount', 'value' => 10, 'is_visible_to_client' => true],
        ];

        $result = $service->calculate($articles, $lines);

        $this->assertSame(50.0, $result['total_primary']);
        $this->assertSame(125000.0, $result['total_secondary']);
        $this->assertSame('CDF', $result['secondary_currency']);
        $this->assertSame(2500.0, $result['exchange_rate']);
    }

    /** LDV-008: Empty articles returns zero totals */
    public function test_ldv008_empty_articles_zero_totals(): void
    {
        $service = $this->service();

        $result = $service->calculate([], [
            ['internal_code' => 'COMMISSION', 'name' => 'C', 'type' => 'percentage', 'calculation_base' => 'product_price', 'value' => 10, 'is_visible_to_client' => true],
        ]);

        $this->assertSame(0.0, $result['subtotal']);
        $this->assertSame(0.0, $result['lines_breakdown'][0]['amount']);
        $this->assertSame(0.0, $result['total_primary']);
    }

    /** LDV-009: Line with 0 value does not change total */
    public function test_ldv009_zero_value_line_unchanged_total(): void
    {
        $service = $this->service();
        $articles = [['unit_price' => 60, 'quantity' => 1]];
        $lines = [
            ['internal_code' => 'PCT', 'name' => 'Zero %', 'type' => 'percentage', 'calculation_base' => 'product_price', 'value' => 0, 'is_visible_to_client' => true],
            ['internal_code' => 'FIX', 'name' => 'Zero fixed', 'type' => 'fixed_amount', 'value' => 0, 'is_visible_to_client' => true],
            ['internal_code' => 'MAN', 'name' => 'Zero manual', 'type' => 'manual', 'value' => 0, 'is_visible_to_client' => true],
        ];

        $result = $service->calculate($articles, $lines);

        $this->assertSame(60.0, $result['total_primary']);
        $this->assertSame(0.0, $result['lines_total']);
        foreach ($result['lines_breakdown'] as $row) {
            $this->assertSame(0.0, $row['amount']);
        }
    }
}
