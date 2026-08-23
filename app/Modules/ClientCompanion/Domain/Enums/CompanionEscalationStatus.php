<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum CompanionEscalationStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
