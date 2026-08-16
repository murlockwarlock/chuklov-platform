<?php

namespace App\Modules\Knowledge\Domain\Enums;

enum KnowledgeSourceStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
