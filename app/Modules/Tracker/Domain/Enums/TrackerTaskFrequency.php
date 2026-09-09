<?php

namespace App\Modules\Tracker\Domain\Enums;

enum TrackerTaskFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
}
