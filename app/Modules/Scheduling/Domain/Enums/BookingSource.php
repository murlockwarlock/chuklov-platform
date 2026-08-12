<?php

namespace App\Modules\Scheduling\Domain\Enums;

enum BookingSource: string
{
    case Crm = 'crm';
    case Portal = 'portal';
}
