<?php

namespace App\Modules\Knowledge\Domain\Exceptions;

use RuntimeException;

final class KnowledgeRevisionFileUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Файл недоступен.');
    }
}
