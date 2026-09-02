<?php

namespace App\Modules\Knowledge\Domain\Enums;

enum KnowledgeStorageCleanupStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Retryable = 'retryable';
    case Succeeded = 'succeeded';
    case Protected = 'protected';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Protected, self::Failed], true);
    }
}
