<?php

namespace App\Modules\Scheduling\Domain\Enums;

enum BookingEventType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case MeetingLinkUpdated = 'meeting_link_updated';
}
