<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum CompanionFeedbackValue: string
{
    case Helpful = 'helpful';
    case NotHelpful = 'not_helpful';
}
