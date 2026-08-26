<?php

namespace App\Modules\Channels\Domain\Exceptions;

use RuntimeException;

final class NotificationDeliveryException extends RuntimeException
{
    public function __construct(public readonly bool $externalSendStarted, string $message = '')
    {
        parent::__construct($message);
    }
}
