<?php

namespace App\Modules\Tracker\Domain\Enums;

enum TrackerTaskEntryStatus: string
{
    case Completed = 'completed';
    case NotCompleted = 'not_completed';
}
