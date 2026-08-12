<?php

namespace App\Modules\Scheduling\Domain\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Refunded = 'refunded';
}
