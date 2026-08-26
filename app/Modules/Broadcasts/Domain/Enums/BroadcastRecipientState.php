<?php

namespace App\Modules\Broadcasts\Domain\Enums;

enum BroadcastRecipientState: string
{
    case Pending = 'pending';
    case Suppressed = 'suppressed';
    case Claimed = 'claimed';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
