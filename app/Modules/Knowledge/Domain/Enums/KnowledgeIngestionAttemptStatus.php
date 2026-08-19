<?php

namespace App\Modules\Knowledge\Domain\Enums;

enum KnowledgeIngestionAttemptStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    public function isTerminal(): bool
    {
        return $this !== self::Processing;
    }
}
