<?php

namespace App\Modules\Referrals\Domain\Enums;

enum ReferralRewardQualificationRule: string
{
    case FirstSettledPayment = 'first_settled_payment';
    case EverySettledPayment = 'every_settled_payment';

    public function label(): string
    {
        return match ($this) {
            self::FirstSettledPayment => 'После первой подтверждённой оплаты',
            self::EverySettledPayment => 'После каждой подтверждённой оплаты',
        };
    }
}
