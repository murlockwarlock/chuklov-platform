<?php

namespace App\Modules\B2B\Domain\Enums;

enum B2bSalesCallStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
}
