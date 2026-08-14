<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use InvalidArgumentException;

final class OnboardingStageConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'onboarding.stage';
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
            throw new InvalidArgumentException('The onboarding stage condition value is invalid.');
        }

        foreach ($values as $value) {
            if (! is_string($value) || ClientOnboardingStage::tryFrom($value) === null) {
                throw new InvalidArgumentException('The onboarding stage condition value is invalid.');
            }
        }
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        if ($context->onboarding === null) {
            return false;
        }

        $actual = $context->onboarding->current_stage->value;

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === (string) $condition->value,
            ScenarioConditionOperator::NotEquals => $actual !== (string) $condition->value,
            ScenarioConditionOperator::In => is_array($condition->value) && in_array($actual, array_map('strval', $condition->value), true),
            ScenarioConditionOperator::Exists => true,
        };
    }
}
