<?php

namespace App\Modules\AI\Domain\Exceptions;

use RuntimeException;

class AiKillSwitchException extends RuntimeException
{
    public function __construct(string $message = 'AI capability or provider is disabled by safety policy.')
    {
        parent::__construct($message);
    }
}
