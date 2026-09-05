<?php

namespace App\Modules\Referrals\Domain\Enums;

enum ReferralRewardFormula: string
{
    case FixedAmount = 'fixed_amount';
    case PercentageOfSettlement = 'percentage_of_settlement';

    public function label(): string
    {
        return match ($this) {
            self::FixedAmount => 'Фиксированная сумма',
            self::PercentageOfSettlement => 'Процент от оплаты',
        };
    }
}
