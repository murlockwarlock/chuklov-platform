<?php

namespace App\Modules\Finance\Domain\Enums;

enum PaymentGatewayStatus: string
{
    case Failed = 'failed';
    case Pending = 'pending';
    case Settled = 'settled';
}
