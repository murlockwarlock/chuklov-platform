<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Scenarios\Application\BooleanScenarioCondition;
use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;

final class TrackerAccessConditionEvaluator implements ScenarioConditionEvaluator
{
    public function __construct(private readonly ResolveTrackerAccess $access) {}

    public function type(): string
    {
        return 'tracker.access';
    }

    public function validate(ScenarioCondition $condition): void
    {
        BooleanScenarioCondition::values($condition);
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        if ($context->client === null) {
            return false;
        }

        $actual = $this->access->handle($context->client)->allowed();

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::NotEquals => $actual !== BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::In => in_array($actual, BooleanScenarioCondition::values($condition), true),
            ScenarioConditionOperator::Exists => $actual,
        };
    }
}
