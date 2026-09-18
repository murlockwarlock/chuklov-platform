<?php

namespace App\Modules\Finance\Domain\Exceptions;

use RuntimeException;
use Throwable;

final class PaymentGatewayProviderException extends RuntimeException implements PaymentGatewayInitiationFailure
{
    public function __construct(
        private readonly string $failureReason,
        private readonly string $safeClientMessage,
        private readonly bool $notifyOperations,
        string $safeOperatorMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeOperatorMessage, 0, $previous);
    }

    public function reasonCode(): string
    {
        return $this->failureReason;
    }

    public function clientMessage(): string
    {
        return $this->safeClientMessage;
    }

    public function shouldNotifyOperations(): bool
    {
        return $this->notifyOperations;
    }

    public function isAmbiguous(): bool
    {
        return true;
    }
}
