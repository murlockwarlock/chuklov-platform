<?php

namespace App\Modules\Scheduling\Domain\Enums;

enum PaymentRequirementType: string
{
    case FullPayment = 'full_payment';
    case TransportDeposit = 'transport_deposit';
}
