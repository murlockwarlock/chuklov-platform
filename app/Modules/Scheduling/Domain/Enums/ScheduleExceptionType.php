<?php

namespace App\Modules\Scheduling\Domain\Enums;

enum ScheduleExceptionType: string
{
    case DayOff = 'day_off';
    case CustomWindow = 'custom_window';
}
