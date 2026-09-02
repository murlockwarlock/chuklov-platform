<?php

namespace App\Modules\B2B\Domain\Enums;

enum B2bLeadSource: string
{
    case Portal = 'portal';
    case Telegram = 'telegram';
    case Crm = 'crm';
}
