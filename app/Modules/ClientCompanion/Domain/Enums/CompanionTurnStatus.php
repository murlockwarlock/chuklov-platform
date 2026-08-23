<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum CompanionTurnStatus: string
{
    case Assembling = 'assembling';
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Escalated = 'escalated';
    case Paused = 'paused';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Escalated, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Assembling, self::Pending, self::Processing], true);
    }
}
