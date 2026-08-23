<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum CompanionImageReferenceMode: string
{
    case None = 'none';
    case RecentTurn = 'recent_turn';
}
