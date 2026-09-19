<?php

namespace App\Modules\Finance\Domain\Enums;

enum PaymentGatewayEventType: string
{
    case Chargeback = 'chargeback';
    case Failure = 'failure';
    case Refund = 'refund';
    case Settlement = 'settlement';
    case Unknown = 'unknown';
}
