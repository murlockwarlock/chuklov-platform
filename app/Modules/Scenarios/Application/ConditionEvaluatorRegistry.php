<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioConditionSet;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use InvalidArgumentException;

final class ConditionEvaluatorRegistry
{
    /**
     * @param  list<ScenarioConditionEvaluator>  $evaluators
     */
    public function __construct(private readonly array $evaluators) {}

    public function matches(ScenarioConditionSet $conditions, ScenarioEvaluationContext $context): bool
    {
        foreach ($conditions->conditions as $condition) {
            $evaluator = $this->evaluator($condition->type);

            if ($evaluator === null) {
                return false;
            }

            try {
                $evaluator->validate($condition);
            } catch (InvalidArgumentException) {
                return false;
            }

            if (! $evaluator->evaluate($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    public function validate(ScenarioConditionSet $conditions): void
    {
        foreach ($conditions->conditions as $condition) {
            $evaluator = $this->evaluator($condition->type);

            if ($evaluator === null) {
                throw new InvalidArgumentException('The scenario condition type is not supported.');
            }

            $evaluator->validate($condition);
        }
    }

    private function evaluator(string $type): ?ScenarioConditionEvaluator
    {
        foreach ($this->evaluators as $evaluator) {
            if ($evaluator->type() === $type) {
                return $evaluator;
            }
        }

        return null;
    }
}
