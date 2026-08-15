<?php

namespace App\Modules\Finance\Domain\Enums;

enum FinancialLedgerEntryType: string
{
    case Correction = 'correction';
    case FakeGatewaySettlement = 'fake_gateway_settlement';
    case ManualPayment = 'manual_payment';
}
