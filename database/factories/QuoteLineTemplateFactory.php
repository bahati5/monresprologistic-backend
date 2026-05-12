<?php

namespace Database\Factories;

use App\Models\QuoteLineTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuoteLineTemplate>
 */
class QuoteLineTemplateFactory extends Factory
{
    protected $model = QuoteLineTemplate::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'internal_code' => strtoupper(fake()->unique()->lexify('?????_???')),
            'description' => fake()->sentence(),
            'type' => fake()->randomElement(['percentage', 'fixed_amount', 'manual']),
            'calculation_base' => 'product_price',
            'default_value' => fake()->randomFloat(2, 1, 50),
            'is_mandatory' => false,
            'is_visible_to_client' => true,
            'is_active' => true,
            'display_order' => fake()->numberBetween(0, 20),
            'applies_to' => 'assisted_purchase',
            'behavior' => fake()->randomElement(['mandatory', 'optional', 'optional_included']),
        ];
    }
}
