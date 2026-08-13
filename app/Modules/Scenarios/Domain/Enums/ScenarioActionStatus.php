<?php

namespace App\Modules\Scenarios\Domain\Enums;

enum ScenarioActionStatus: string
{
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Delivered = 'delivered';
    case Retryable = 'retryable';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Suppressed, self::Cancelled], true);
    }
}
