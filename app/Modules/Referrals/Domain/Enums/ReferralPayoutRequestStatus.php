<?php

namespace App\Modules\Referrals\Domain\Enums;

enum ReferralPayoutRequestStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Запрошена',
            self::Approved => 'Одобрена',
            self::Paid => 'Отмечена как выплаченная',
            self::Rejected => 'Отклонена',
            self::Cancelled => 'Отменена',
        };
    }

    public function isPending(): bool
    {
        return in_array($this, [self::Requested, self::Approved], true);
    }
}
