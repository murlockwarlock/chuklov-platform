<?php

namespace App\Modules\AI\Domain\Exceptions;

use RuntimeException;
use Throwable;

class AiRagRetrievalException extends RuntimeException
{
    public function __construct(
        public readonly string $errorMessage,
        public readonly string $reason = 'infrastructure',
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorMessage, 0, $previous);
    }
}
