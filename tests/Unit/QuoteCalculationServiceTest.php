<?php

namespace Tests\Unit;

use App\Services\QuoteCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuoteCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QuoteCalculationService();

        \App\Models\Setting::setValue('quote_primary_currency', 'USD');
        \App\Models\Setting::setValue('quote_secondary_currency_enabled', '0');
    }

    public function test_calculates_subtotal_from_articles(): void
    {
        $articles = [
            ['unit_price' => 50, 'quantity' => 2],
            ['unit_price' => 30, 'quantity' => 1],
        ];

        $result = $this->service->calculate($articles, []);

        $this->assertEquals(130.0, $result['subtotal']);
        $this->assertEquals(130.0, $result['total_primary']);
    }

    public function test_calculates_percentage_line_on_product_price(): void
    {
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
        ];

        $result = $this->service->calculate($articles, $lines);

        $this->assertEquals(100.0, $result['subtotal']);
        $this->assertEquals(10.0, $result['lines_breakdown'][0]['amount']);
        $this->assertEquals(110.0, $result['total_primary']);
    }

    public function test_calculates_fixed_amount_line(): void
    {
        $articles = [['unit_price' => 80, 'quantity' => 1]];

        $lines = [
            [
                'internal_code' => 'SHIPPING',
                'name' => 'Fret',
                'type' => 'fixed_amount',
                'value' => 25,
                'is_visible_to_client' => true,
            ],
        ];

        $result = $this->service->calculate($articles, $lines);

        $this->assertEquals(25.0, $result['lines_breakdown'][0]['amount']);
        $this->assertEquals(105.0, $result['total_primary']);
    }

    public function test_calculates_percentage_on_subtotal_after_commission(): void
    {
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
                'internal_code' => 'BANK_FEE',
                'name' => 'Frais bancaires',
                'type' => 'percentage',
                'calculation_base' => 'subtotal_after_commission',
                'value' => 3,
                'is_visible_to_client' => true,
            ],
        ];

        $result = $this->service->calculate($articles, $lines);

        $this->assertEquals(10.0, $result['lines_breakdown'][0]['amount']);
        $this->assertEquals(3.30, $result['lines_breakdown'][1]['amount']);
        $this->assertEquals(113.30, $result['total_primary']);
    }

    public function test_calculates_with_secondary_currency(): void
    {
        \App\Models\Setting::setValue('quote_secondary_currency_enabled', '1');
        \App\Models\Setting::setValue('quote_secondary_currency', 'CDF');
        \App\Models\Setting::setValue('quote_secondary_currency_rate', '2800');

        $articles = [['unit_price' => 100, 'quantity' => 1]];
        $lines = [];

        $result = $this->service->calculate($articles, $lines);

        $this->assertEquals(100.0, $result['total_primary']);
        $this->assertEquals(280000, $result['total_secondary']);
        $this->assertEquals('CDF', $result['secondary_currency']);
        $this->assertEquals(2800.0, $result['exchange_rate']);
    }

    public function test_manual_line_uses_value_directly(): void
    {
        $articles = [['unit_price' => 50, 'quantity' => 1]];

        $lines = [
            [
                'internal_code' => 'CUSTOM',
                'name' => 'Ajustement',
                'type' => 'manual',
                'value' => 15,
                'is_visible_to_client' => true,
            ],
        ];

        $result = $this->service->calculate($articles, $lines);

        $this->assertEquals(15.0, $result['lines_breakdown'][0]['amount']);
        $this->assertEquals(65.0, $result['total_primary']);
    }

    public function test_builds_snapshot_with_urgency_surcharge(): void
    {
        $articles = [['unit_price' => 100, 'quantity' => 1]];
        $lines = [
            ['internal_code' => 'FEE', 'name' => 'Fee', 'type' => 'fixed_amount', 'value' => 20, 'is_visible_to_client' => true],
        ];

        $calcResult = $this->service->calculate($articles, $lines);

        $snapshot = $this->service->buildSnapshot(
            $articles,
            $lines,
            $calcResult,
            '5-7 jours',
            'Test message',
            true,
            15.0,
        );

        $this->assertTrue($snapshot['is_urgent']);
        $this->assertEquals(15.0, $snapshot['urgency_surcharge_percent']);
        $expectedTotal = 120.0 * 1.15;
        $this->assertEquals(round($expectedTotal, 2), $snapshot['total_primary']);
    }

    public function test_empty_articles_returns_zero(): void
    {
        $result = $this->service->calculate([], []);

        $this->assertEquals(0.0, $result['subtotal']);
        $this->assertEquals(0.0, $result['total_primary']);
    }
}
