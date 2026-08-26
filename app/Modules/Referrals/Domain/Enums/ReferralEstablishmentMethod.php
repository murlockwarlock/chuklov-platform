<?php

namespace App\Modules\Referrals\Domain\Enums;

enum ReferralEstablishmentMethod: string
{
    case AutomaticReferralLink = 'automatic_referral_link';
    case ManualCrm = 'manual_crm';
}
