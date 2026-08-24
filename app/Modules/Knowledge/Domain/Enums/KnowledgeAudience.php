<?php

namespace App\Modules\Knowledge\Domain\Enums;

enum KnowledgeAudience: string
{
    case InternalStaff = 'internal_staff';
    case ClientCompanion = 'client_companion';
}
