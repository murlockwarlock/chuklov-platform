<?php

namespace App\Modules\Referrals\Domain\Enums;

enum ReferralRewardLedgerEntryType: string
{
    case Earned = 'earned';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Earned => 'Начисление',
            self::Reversed => 'Сторно',
        };
    }
}
