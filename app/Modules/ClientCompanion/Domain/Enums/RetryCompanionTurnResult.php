<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum RetryCompanionTurnResult: string
{
    case Queued = 'queued';
    case AlreadyRequested = 'already_requested';
    case Unavailable = 'unavailable';
}
