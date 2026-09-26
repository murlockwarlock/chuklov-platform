<?php

namespace App\Modules\Referrals\Domain\Enums;

enum ReferralRewardLedgerEntryType: string
{
    case Earned = 'earned';
    case Reversed = 'reversed';
    case ManualCredit = 'manual_credit';
    case Redeemed = 'redeemed';
    case Restored = 'restored';

    public function label(): string
    {
        return match ($this) {
            self::Earned => 'Начисление',
            self::Reversed => 'Сторно',
            self::ManualCredit => 'Ручной бонус',
            self::Redeemed => 'Использование бонуса',
            self::Restored => 'Возврат бонуса',
        };
    }
}
