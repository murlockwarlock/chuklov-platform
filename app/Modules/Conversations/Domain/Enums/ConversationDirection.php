<?php

namespace App\Modules\Conversations\Domain\Enums;

enum ConversationDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
