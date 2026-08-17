<?php

namespace App\Modules\AI\Domain\Exceptions;

use RuntimeException;
use Throwable;

class AiRagRetrievalException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'infrastructure',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
