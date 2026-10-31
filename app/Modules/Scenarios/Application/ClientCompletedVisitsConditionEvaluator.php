<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use InvalidArgumentException;

final class ClientCompletedVisitsConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'client.completed_visits';
    }

    public function validate(ScenarioCondition $condition): void
    {
        if ($condition->operator === ScenarioConditionOperator::Exists) {
            return;
        }

        $values = $condition->operator === ScenarioConditionOperator::In
            ? $condition->value
            : [$condition->value];
        if (! is_array($values) || $values === []) {
            throw new InvalidArgumentException('The completed visit count condition value is invalid.');
        }

        foreach ($values as $value) {
            if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) === false) {
                throw new InvalidArgumentException('The completed visit count condition value is invalid.');
            }
        }
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        if ($context->client === null) {
            return false;
        }

        $actual = $context->client->bookings()
            ->where('bookings.organization_id', $context->event->organization_id)
            ->where('bookings.status', BookingStatus::Completed->value)
            ->count();
        if ($condition->operator === ScenarioConditionOperator::Exists) {
            return $actual > 0;
        }

        $values = $condition->operator === ScenarioConditionOperator::In
            ? array_map('intval', $condition->value)
            : [(int) $condition->value];

        if ($condition->operator === ScenarioConditionOperator::Equals) {
            return $actual === $values[0];
        }
        if ($condition->operator === ScenarioConditionOperator::NotEquals) {
            return $actual !== $values[0];
        }

        return in_array($actual, $values, true);
    }
}
