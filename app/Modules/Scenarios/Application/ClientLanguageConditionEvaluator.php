<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use InvalidArgumentException;

final class ClientLanguageConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'client.language';
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
            throw new InvalidArgumentException('The client language condition value is invalid.');
        }

        foreach ($values as $value) {
            if (! is_string($value) || ! in_array(strtolower($value), ['en', 'ru'], true)) {
                throw new InvalidArgumentException('The client language condition value is invalid.');
            }
        }
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        if ($context->client === null) {
            return false;
        }

        $actual = strtolower((string) ($context->client->language ?? 'en'));

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === strtolower((string) $condition->value),
            ScenarioConditionOperator::NotEquals => $actual !== strtolower((string) $condition->value),
            ScenarioConditionOperator::In => is_array($condition->value) && in_array($actual, array_map(static fn (mixed $value): string => strtolower((string) $value), $condition->value), true),
            ScenarioConditionOperator::Exists => $actual !== '',
        };
    }
}
