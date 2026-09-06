<?php

namespace App\Modules\Referrals\Domain\Enums;

enum ReferralPartnerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активен',
            self::Inactive => 'Отключён',
        };
    }
}
