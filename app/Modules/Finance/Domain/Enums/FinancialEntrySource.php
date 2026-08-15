<?php

namespace App\Modules\Finance\Domain\Enums;

enum FinancialEntrySource: string
{
    case Crm = 'crm';
    case FakeGateway = 'fake_gateway';
    case System = 'system';
}
