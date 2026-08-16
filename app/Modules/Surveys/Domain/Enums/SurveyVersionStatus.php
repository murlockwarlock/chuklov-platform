<?php

namespace App\Modules\Surveys\Domain\Enums;

enum SurveyVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
