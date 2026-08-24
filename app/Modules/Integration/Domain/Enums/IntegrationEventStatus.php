<?php

namespace App\Modules\Integration\Domain\Enums;

enum IntegrationEventStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Retryable = 'retryable';
    case Processed = 'processed';
    case Failed = 'failed';
}
