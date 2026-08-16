<?php

namespace App\Modules\AI\Domain\Exceptions;

use RuntimeException;

class AiProviderUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'No healthy or enabled AI providers available for requested capability.')
    {
        parent::__construct($message);
    }
}
