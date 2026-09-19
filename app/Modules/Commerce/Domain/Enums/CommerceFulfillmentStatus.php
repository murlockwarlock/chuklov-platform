<?php

namespace App\Modules\Commerce\Domain\Enums;

enum CommerceFulfillmentStatus: string
{
    case Failed = 'failed';
    case Fulfilled = 'fulfilled';
    case Pending = 'pending';
    case Processing = 'processing';
}
