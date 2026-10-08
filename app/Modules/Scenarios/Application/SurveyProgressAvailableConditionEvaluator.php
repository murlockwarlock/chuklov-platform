<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Surveys\Application\SurveyComparisonPresentation;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;

final class SurveyProgressAvailableConditionEvaluator implements ScenarioConditionEvaluator
{
    public function __construct(private readonly SurveyComparisonPresentation $presentation) {}

    public function type(): string
    {
        return 'survey.progress_available';
    }

    public function validate(ScenarioCondition $condition): void
    {
        BooleanScenarioCondition::values($condition);
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        $actual = $this->hasProgress($context);

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::NotEquals => $actual !== BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::In => in_array($actual, BooleanScenarioCondition::values($condition), true),
            ScenarioConditionOperator::Exists => $actual,
        };
    }

    private function hasProgress(ScenarioEvaluationContext $context): bool
    {
        $attemptId = $context->event->payload['attempt_id'] ?? null;

        if (! is_numeric($attemptId)) {
            return false;
        }

        $comparison = SurveyComparison::query()
            ->where('organization_id', $context->event->organization_id)
            ->where('current_attempt_id', (int) $attemptId)
            ->first();

        if (! $comparison instanceof SurveyComparison) {
            return false;
        }

        $current = SurveyAttempt::query()
            ->where('organization_id', $context->event->organization_id)
            ->whereKey($comparison->current_attempt_id)
            ->first();
        $previous = SurveyAttempt::query()
            ->where('organization_id', $context->event->organization_id)
            ->whereKey($comparison->previous_attempt_id)
            ->first();
        $locale = strtolower((string) $context->client?->language) === 'ru' ? 'ru' : 'en';

        return $this->presentation->handle($comparison, $current, $previous, $locale)['hasData'] === true;
    }
}
