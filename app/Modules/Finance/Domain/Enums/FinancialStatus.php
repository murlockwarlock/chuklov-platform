<?php

namespace App\Modules\Finance\Domain\Enums;

enum FinancialStatus: string
{
    case Outstanding = 'outstanding';
    case PartiallyPaid = 'partially_paid';
    case Settled = 'settled';
}
