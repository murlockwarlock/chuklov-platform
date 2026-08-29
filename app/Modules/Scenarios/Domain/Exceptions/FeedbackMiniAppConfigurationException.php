<?php

namespace App\Modules\Scenarios\Domain\Exceptions;

use RuntimeException;
use Throwable;

final class FeedbackMiniAppConfigurationException extends RuntimeException
{
    public const ERROR_CODE = 'feedback_mini_app_configuration_unavailable';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The feedback Telegram Mini App is not configured.', 0, $previous);
    }
}
