<?php

namespace App\Modules\Channels\Domain\ValueObjects;

use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;

final readonly class NotificationDeliveryResult
{
    public function __construct(
        public NotificationDeliveryOutcome $outcome,
        public ?string $providerReference = null,
        public ?string $errorCode = null,
    ) {}

    public static function delivered(?string $providerReference = null): self
    {
        return new self(NotificationDeliveryOutcome::Delivered, $providerReference);
    }

    public static function retryable(string $errorCode): self
    {
        return new self(NotificationDeliveryOutcome::Retryable, errorCode: $errorCode);
    }

    public static function permanentFailure(string $errorCode): self
    {
        return new self(NotificationDeliveryOutcome::PermanentFailure, errorCode: $errorCode);
    }

    public static function unavailable(string $errorCode): self
    {
        return new self(NotificationDeliveryOutcome::Unavailable, errorCode: $errorCode);
    }

    public static function unknown(string $errorCode): self
    {
        return new self(NotificationDeliveryOutcome::Unknown, errorCode: $errorCode);
    }
}
