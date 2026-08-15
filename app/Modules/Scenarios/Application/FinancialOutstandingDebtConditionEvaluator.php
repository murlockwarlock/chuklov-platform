<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use InvalidArgumentException;

final class FinancialOutstandingDebtConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'finance.has_outstanding_debt';
    }

    public function validate(ScenarioCondition $condition): void
    {
        if ($condition->operator === ScenarioConditionOperator::Exists) {
            return;
        }

        $values = $condition->operator === ScenarioConditionOperator::In
            ? $condition->value
            : [$condition->value];

        if (! is_array($values)) {
            throw new InvalidArgumentException('The finance condition value is invalid.');
        }

        foreach ($values as $value) {
            if (! in_array((string) $value, ['true', 'false'], true)) {
                throw new InvalidArgumentException('The finance condition value is invalid.');
            }
        }
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        $actual = $context->obligation !== null
            && app(ScenarioContextFactory::class)->financeDebtIsCurrent($context);

        return match ($condition->operator) {
            ScenarioConditionOperator::Exists => $actual,
            ScenarioConditionOperator::Equals => $actual === filter_var($condition->value, FILTER_VALIDATE_BOOLEAN),
            ScenarioConditionOperator::NotEquals => $actual !== filter_var($condition->value, FILTER_VALIDATE_BOOLEAN),
            ScenarioConditionOperator::In => is_array($condition->value)
                && in_array($actual ? 'true' : 'false', array_map('strval', $condition->value), true),
        };
    }
}
