<?php

namespace App\Modules\Channels\Domain\ValueObjects;

final readonly class NotificationMessage
{
    public function __construct(
        public string $recipientExternalId,
        public string $body,
        public ?string $subject,
        public string $locale,
        public string $idempotencyKey,
        public bool $requireKnownExternalOutcome = false,
        public ?string $webAppUrl = null,
    ) {}
}
