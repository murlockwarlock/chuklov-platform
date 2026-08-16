<?php

namespace App\Modules\Knowledge\Domain\Enums;

enum IngestionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
