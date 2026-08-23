<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum CompanionDeliveryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
}
