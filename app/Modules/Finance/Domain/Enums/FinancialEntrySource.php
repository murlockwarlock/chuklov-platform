<?php

namespace App\Modules\Finance\Domain\Enums;

enum FinancialEntrySource: string
{
    case Crm = 'crm';
    case FakeGateway = 'fake_gateway';
    case GiftCertificate = 'gift_certificate';
    case PaymentGateway = 'payment_gateway';
    case Referral = 'referral';
    case System = 'system';
}
