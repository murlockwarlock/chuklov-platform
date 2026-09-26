<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Feedback\Domain\Enums\NpsBand;
use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use InvalidArgumentException;

final class FeedbackBandConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'feedback.band';
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
            throw new InvalidArgumentException('The feedback band condition value is invalid.');
        }

        foreach ($values as $value) {
            if (! is_string($value) || NpsBand::tryFrom($value) === null) {
                throw new InvalidArgumentException('The feedback band condition value is invalid.');
            }
        }
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        $actual = NpsBand::tryFrom((string) ($context->event->payload['band'] ?? ''));

        if ($actual === null) {
            return false;
        }

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual->value === (string) $condition->value,
            ScenarioConditionOperator::NotEquals => $actual->value !== (string) $condition->value,
            ScenarioConditionOperator::In => is_array($condition->value) && in_array($actual->value, array_map('strval', $condition->value), true),
            ScenarioConditionOperator::Exists => true,
        };
    }
}
