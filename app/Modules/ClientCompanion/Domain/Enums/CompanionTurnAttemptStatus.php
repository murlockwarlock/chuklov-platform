<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum CompanionTurnAttemptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
