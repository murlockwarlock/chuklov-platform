<?php

namespace App\Modules\Scenarios\Domain\Contracts;

use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;

interface ScenarioConditionEvaluator
{
    public function type(): string;

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool;
}
