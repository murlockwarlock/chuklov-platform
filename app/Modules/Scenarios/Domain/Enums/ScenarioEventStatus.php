<?php

namespace App\Modules\Scenarios\Domain\Enums;

enum ScenarioEventStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Retryable = 'retryable';
    case Failed = 'failed';
}
