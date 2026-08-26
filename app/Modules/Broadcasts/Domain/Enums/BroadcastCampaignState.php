<?php

namespace App\Modules\Broadcasts\Domain\Enums;

enum BroadcastCampaignState: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Dispatching = 'dispatching';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}
