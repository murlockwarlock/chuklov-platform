<?php

namespace App\Modules\Integration\Domain\Enums;

enum IntegrationEventType: string
{
    case FinanceObligationSettled = 'finance.obligation.settled';
    case B2bSalesCallProviderSync = 'b2b.sales_call.provider_sync';
    case BookingProviderSync = 'booking.provider_sync';
}
