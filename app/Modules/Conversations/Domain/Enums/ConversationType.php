<?php

namespace App\Modules\Conversations\Domain\Enums;

enum ConversationType: string
{
    case Channel = 'channel';
    case ClientCompanion = 'client_companion';
}
