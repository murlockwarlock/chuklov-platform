<?php

namespace App\Modules\Scenarios\Domain\Enums;

enum ScenarioDeliveryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Delivered = 'delivered';
    case Retryable = 'retryable';
    case PermanentFailure = 'permanent_failure';
    case Unavailable = 'unavailable';
    case Suppressed = 'suppressed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::PermanentFailure, self::Unavailable, self::Suppressed], true);
    }
}
