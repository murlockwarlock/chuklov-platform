<?php

namespace App\Modules\AI\Domain\Exceptions;

use RuntimeException;

class AiProviderUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'No healthy or enabled AI providers available for requested capability.',
        public readonly bool $configurationMissing = false,
        public readonly bool $providerDisabled = false,
    ) {
        parent::__construct($message);
    }
}
