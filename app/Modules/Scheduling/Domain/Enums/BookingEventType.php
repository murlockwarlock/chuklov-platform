<?php

namespace App\Modules\Scheduling\Domain\Enums;

enum BookingEventType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
}
