<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;

final class ClientLanguageConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'client.language';
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
