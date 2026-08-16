<?php

namespace App\Modules\Surveys\Domain\Enums;

enum SurveyAttemptStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
