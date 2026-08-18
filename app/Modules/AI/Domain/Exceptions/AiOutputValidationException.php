<?php

namespace App\Modules\AI\Domain\Exceptions;

use RuntimeException;

class AiOutputValidationException extends RuntimeException
{
    public function __construct(string $message = 'AI output failed JSON schema validation.')
    {
        parent::__construct($message);
    }
}
