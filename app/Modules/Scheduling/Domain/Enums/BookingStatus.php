<?php

namespace App\Modules\Scheduling\Domain\Enums;

enum BookingStatus: string
{
    case Requested = 'requested';
    case PendingReview = 'pending_review';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    /** @return list<string> */
    public static function blockingValues(): array
    {
        return [
            self::Requested->value,
            self::Confirmed->value,
        ];
    }

    public function blocksAvailability(): bool
    {
        return in_array($this, [self::Requested, self::Confirmed], true);
    }
}
