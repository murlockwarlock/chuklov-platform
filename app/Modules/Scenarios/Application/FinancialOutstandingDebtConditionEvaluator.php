<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;

final class FinancialOutstandingDebtConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'finance.has_outstanding_debt';
    }

    public function validate(ScenarioCondition $condition): void
    {
        BooleanScenarioCondition::values($condition);
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        $actual = $context->obligation !== null
            && app(ScenarioContextFactory::class)->financeDebtIsCurrent($context);

        return match ($condition->operator) {
            ScenarioConditionOperator::Exists => $actual,
            ScenarioConditionOperator::Equals => $actual === BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::NotEquals => $actual !== BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::In => is_array($condition->value)
                && in_array($actual, BooleanScenarioCondition::values($condition), true),
        };
    }
}
