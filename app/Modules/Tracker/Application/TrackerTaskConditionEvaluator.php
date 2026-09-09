<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Scenarios\Application\BooleanScenarioCondition;
use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Carbon\CarbonImmutable;

final class TrackerTaskConditionEvaluator implements ScenarioConditionEvaluator
{
    public function __construct(private readonly TrackerTaskSchedule $schedule) {}

    public function type(): string
    {
        return 'tracker.task_active';
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

        $taskId = $context->event->payload['task_id'] ?? null;
        $task = is_int($taskId) || (is_string($taskId) && ctype_digit($taskId))
            ? TrackerTask::query()
                ->where('organization_id', $context->event->organization_id)
                ->where('client_id', $context->client->getKey())
                ->whereKey((int) $taskId)
                ->first()
            : null;
        $at = $context->evaluationEndsAt ?? CarbonImmutable::parse((string) $context->event->occurred_at);
        $date = $this->schedule->localDate($context->client, $at);
        $actual = $task instanceof TrackerTask && $task->active && $this->schedule->isDue($task, $date);

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::NotEquals => $actual !== BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::In => in_array($actual, BooleanScenarioCondition::values($condition), true),
            ScenarioConditionOperator::Exists => $actual,
        };
    }
}
