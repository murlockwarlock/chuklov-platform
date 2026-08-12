<?php

namespace App\Modules\Conversations\Domain\Enums;

enum ConversationAuthorType: string
{
    case Client = 'client';
    case Staff = 'staff';
    case Ai = 'ai';
    case System = 'system';
}
