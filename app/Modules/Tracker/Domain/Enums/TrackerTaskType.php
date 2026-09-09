<?php

namespace App\Modules\Tracker\Domain\Enums;

enum TrackerTaskType: string
{
    case Exercise = 'exercise';
    case Hydration = 'hydration';
    case Practice = 'practice';
    case Other = 'other';
}
