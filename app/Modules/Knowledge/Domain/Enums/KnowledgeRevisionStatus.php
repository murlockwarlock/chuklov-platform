<?php

namespace App\Modules\Knowledge\Domain\Enums;

enum KnowledgeRevisionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Stale = 'stale';
    case Retired = 'retired';
}
