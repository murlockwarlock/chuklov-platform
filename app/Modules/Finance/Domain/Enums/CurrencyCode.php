<?php

namespace App\Modules\Finance\Domain\Enums;

enum CurrencyCode: string
{
    case AED = 'AED';
    case CNY = 'CNY';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case JPY = 'JPY';
    case KZT = 'KZT';
    case RUB = 'RUB';
    case THB = 'THB';
    case TRY = 'TRY';
    case USD = 'USD';
}
