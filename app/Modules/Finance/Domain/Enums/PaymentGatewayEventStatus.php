<?php

namespace App\Modules\Finance\Domain\Enums;

enum PaymentGatewayEventStatus: string
{
    case PendingLink = 'pending_link';
    case Processing = 'processing';
    case Processed = 'processed';
    case Rejected = 'rejected';
    case ReconciliationRequired = 'reconciliation_required';
}
