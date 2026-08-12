<?php

namespace App\Modules\Scheduling\Domain\Enums;

enum MeetingLinkMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
