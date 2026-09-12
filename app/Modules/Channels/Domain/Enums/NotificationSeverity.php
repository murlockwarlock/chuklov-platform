<?php

namespace App\Modules\Channels\Domain\Enums;

enum NotificationSeverity: string
{
    case Info = 'info';
    case Action = 'action';
    case High = 'high';
    case Critical = 'critical';
}
