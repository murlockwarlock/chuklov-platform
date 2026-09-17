<?php

namespace App\Modules\Finance\Domain\Enums;

enum PaymentGatewayStatus: string
{
    case Failed = 'failed';
    case Initiating = 'initiating';
    case Pending = 'pending';
    case Refunded = 'refunded';
    case Settled = 'settled';
    case Unknown = 'unknown';
}
