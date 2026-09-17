<?php

namespace App\Modules\Commerce\Domain\Enums;

enum PurchaseStatus: string
{
    case Paid = 'paid';
    case PendingPayment = 'pending_payment';
    case Refunded = 'refunded';
}
