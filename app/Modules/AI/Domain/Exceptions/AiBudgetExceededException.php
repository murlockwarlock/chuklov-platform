<?php

namespace App\Modules\AI\Domain\Exceptions;

use RuntimeException;

class AiBudgetExceededException extends RuntimeException
{
    public function __construct(string $message = 'AI daily spend budget exceeded for organization.')
    {
        parent::__construct($message);
    }
}
