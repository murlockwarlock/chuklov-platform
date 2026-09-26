<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;

final class SurveyAvailableConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'survey.available';
    }

    public function validate(ScenarioCondition $condition): void
    {
        BooleanScenarioCondition::values($condition);
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        $actual = SurveyDefinition::query()
            ->where('organization_id', $context->event->organization_id)
            ->where('is_available', true)
            ->whereHas('activeVersion', fn ($query) => $query
                ->where('organization_id', $context->event->organization_id)
                ->where('status', SurveyVersionStatus::Published->value))
            ->exists();

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::NotEquals => $actual !== BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::In => in_array($actual, BooleanScenarioCondition::values($condition), true),
            ScenarioConditionOperator::Exists => $actual,
        };
    }
}
