<?php

namespace App\Modules\Security\Infrastructure\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

class RedactSensitiveLogTap
{
    public function __invoke(Logger $logger): void
    {
        $underlyingLogger = $logger->getLogger();

        if ($underlyingLogger instanceof MonologLogger) {
            $underlyingLogger->pushProcessor(new RedactSensitiveLogData);
        }
    }
}
