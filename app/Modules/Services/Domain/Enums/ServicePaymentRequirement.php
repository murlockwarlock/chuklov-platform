<?php

namespace App\Modules\Services\Domain\Enums;

enum ServicePaymentRequirement: string
{
    case Postpay = 'postpay';
    case PrepayFull = 'prepay_full';
}
