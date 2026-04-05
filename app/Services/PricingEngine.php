<?php

namespace App\Services;

use App\Models\PricingRule;
use Illuminate\Support\Collection;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Évalue les règles de tarification paramétrables via Symfony ExpressionLanguage.
 * Variables typiques : real_weight_kg, volumetric_weight_kg, declared_value, rate, fixed_fee
 */
class PricingEngine
{
    public function __construct(
        protected Collection $rules,
        protected ExpressionLanguage $expressions = new ExpressionLanguage,
    ) {}

    public static function forContext(?int $agencyId = null, ?int $zoneId = null): self
    {
        $query = PricingRule::query()->where('is_active', true)->orderByDesc('priority');

        if ($agencyId) {
            $query->where(function ($q) use ($agencyId) {
                $q->whereNull('agency_id')->orWhere('agency_id', $agencyId);
            });
        }

        if ($zoneId) {
            $query->where(function ($q) use ($zoneId) {
                $q->whereNull('zone_id')->orWhere('zone_id', $zoneId);
            });
        }

        return new self($query->get());
    }

    /**
     * @param  array<string, float|int>  $variables
     */
    public function quote(array $variables): ?float
    {
        foreach ($this->rules as $rule) {
            if (! $this->conditionsMatch($rule->conditions ?? [], $variables)) {
                continue;
            }

            return $this->evaluateFormula($rule->formula, $variables);
        }

        return null;
    }

    protected function conditionsMatch(?array $conditions, array $variables): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $key => $expected) {
            if (! array_key_exists($key, $variables)) {
                return false;
            }
            if (is_array($expected)) {
                if (($expected['op'] ?? null) === 'gte' && ! ($variables[$key] >= ($expected['value'] ?? 0))) {
                    return false;
                }
            } elseif ($variables[$key] != $expected) {
                return false;
            }
        }

        return true;
    }

    protected function evaluateFormula(string $formula, array $variables): float
    {
        try {
            return round((float) $this->expressions->evaluate($formula, $variables), 2);
        } catch (\Throwable) {
            return 0.0;
        }
    }
}
