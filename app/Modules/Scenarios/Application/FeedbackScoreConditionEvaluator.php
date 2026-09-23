<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use InvalidArgumentException;

final class FeedbackScoreConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'feedback.score';
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
            throw new InvalidArgumentException('The feedback score condition value is invalid.');
        }

        foreach ($values as $value) {
            if (! is_numeric($value) || (int) $value < 1 || (int) $value > 10) {
                throw new InvalidArgumentException('The feedback score condition value is invalid.');
            }
        }
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        $score = $context->event->payload['score'] ?? null;
        if (! is_numeric($score)) {
            return false;
        }

        $actual = (int) $score;

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === (int) $condition->value,
            ScenarioConditionOperator::NotEquals => $actual !== (int) $condition->value,
            ScenarioConditionOperator::In => is_array($condition->value)
                && in_array($actual, array_map(static fn (mixed $value): int => (int) $value, $condition->value), true),
            ScenarioConditionOperator::Exists => true,
        };
    }
}
