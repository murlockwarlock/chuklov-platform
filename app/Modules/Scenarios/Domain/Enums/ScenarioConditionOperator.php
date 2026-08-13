<?php

namespace App\Modules\Scenarios\Domain\Enums;

enum ScenarioConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case In = 'in';
    case Exists = 'exists';
}
