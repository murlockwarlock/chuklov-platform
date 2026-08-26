<?php

namespace App\Modules\Integration\Domain\Enums;

enum IntegrationEventType: string
{
    case FinanceObligationSettled = 'finance.obligation.settled';
}
