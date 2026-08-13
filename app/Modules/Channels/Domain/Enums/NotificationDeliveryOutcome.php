<?php

namespace App\Modules\Channels\Domain\Enums;

enum NotificationDeliveryOutcome: string
{
    case Delivered = 'delivered';
    case Retryable = 'retryable';
    case PermanentFailure = 'permanent_failure';
    case Unavailable = 'unavailable';
    case Suppressed = 'suppressed';
    case Unknown = 'unknown';
}
