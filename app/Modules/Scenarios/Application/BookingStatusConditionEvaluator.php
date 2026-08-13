<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;

final class BookingStatusConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'booking.status';
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        if ($context->booking === null) {
            return false;
        }

        $actual = $context->booking->status->value;

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === (string) $condition->value,
            ScenarioConditionOperator::NotEquals => $actual !== (string) $condition->value,
            ScenarioConditionOperator::In => is_array($condition->value) && in_array($actual, array_map('strval', $condition->value), true),
            ScenarioConditionOperator::Exists => true,
        };
    }
}
