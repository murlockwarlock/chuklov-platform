<?php

namespace App\Modules\Finance\Domain\Enums;

enum PaymentGatewayEventType: string
{
    case Failure = 'failure';
    case Refund = 'refund';
    case Settlement = 'settlement';
}
