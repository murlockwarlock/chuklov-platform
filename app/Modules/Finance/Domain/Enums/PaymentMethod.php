<?php

namespace App\Modules\Finance\Domain\Enums;

enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case ManualCard = 'manual_card';
    case Other = 'other';
}
