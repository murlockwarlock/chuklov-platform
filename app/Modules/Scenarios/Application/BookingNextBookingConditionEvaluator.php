<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;

final class BookingNextBookingConditionEvaluator implements ScenarioConditionEvaluator
{
    public function __construct(private readonly HasQualifyingNextBooking $nextBooking) {}

    public function type(): string
    {
        return 'booking.has_qualifying_next_booking';
    }

    public function validate(ScenarioCondition $condition): void
    {
        BooleanScenarioCondition::values($condition);
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        $actual = $this->nextBooking->forScenario($context);

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::NotEquals => $actual !== BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::In => in_array($actual, BooleanScenarioCondition::values($condition), true),
            ScenarioConditionOperator::Exists => $actual,
        };
    }
}
