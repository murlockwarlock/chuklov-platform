<?php

namespace App\Modules\Scenarios\Domain\Exceptions;

use RuntimeException;
use Throwable;

final class TelegramMiniAppConfigurationException extends RuntimeException
{
    public const ERROR_CODE = 'telegram_mini_app_configuration_unavailable';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The Telegram Mini App entry is not configured.', 0, $previous);
    }
}
