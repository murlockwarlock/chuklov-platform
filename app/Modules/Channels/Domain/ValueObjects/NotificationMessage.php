<?php

namespace App\Modules\Channels\Domain\ValueObjects;

use App\Modules\Channels\Domain\Enums\NotificationMessageMode;

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
        public ?NotificationActionButton $actionButton = null,
        public ?string $mediaUrl = null,
        public NotificationMessageMode $mode = NotificationMessageMode::Text,
        public bool $showCaptionAboveMedia = false,
    ) {}
}
