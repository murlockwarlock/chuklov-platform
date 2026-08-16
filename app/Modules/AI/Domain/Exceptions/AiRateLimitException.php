<?php

namespace App\Modules\AI\Domain\Exceptions;

use RuntimeException;

class AiRateLimitException extends RuntimeException
{
    public function __construct(string $message = 'AI rate limit exceeded for organization.')
    {
        parent::__construct($message);
    }
}
