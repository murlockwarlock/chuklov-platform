<?php

namespace App\Modules\Knowledge\Domain\Enums;

enum KnowledgeStorageCleanupStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Retryable = 'retryable';
    case Succeeded = 'succeeded';
    case Protected = 'protected';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Protected], true);
    }
}
