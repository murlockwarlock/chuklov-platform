<?php

namespace App\Modules\Referrals\Domain\Enums;

enum ReferralRewardCategory: string
{
    case ServiceCredit = 'service_credit';
    case PartnerCash = 'partner_cash';
}
