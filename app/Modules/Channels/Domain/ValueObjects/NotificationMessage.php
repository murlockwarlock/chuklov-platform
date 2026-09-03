<?php

namespace App\Modules\Channels\Domain\ValueObjects;

use App\Modules\Channels\Domain\Enums\NotificationMessageMode;

final readonly class NotificationMessage
{
    /** @var list<NotificationMedia> */
    public array $mediaItems;

    /** @param array<int, mixed> $mediaItems */
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
        public mixed $mediaStream = null,
        public NotificationMessageMode $mode = NotificationMessageMode::Text,
        public bool $showCaptionAboveMedia = false,
        array $mediaItems = [],
    ) {
        if (! array_is_list($mediaItems)) {
            throw new \InvalidArgumentException('Notification media must be a list.');
        }

        foreach ($mediaItems as $media) {
            if (! $media instanceof NotificationMedia) {
                throw new \InvalidArgumentException('Notification media must be a NotificationMedia instance.');
            }
        }

        $this->mediaItems = $mediaItems;
    }
}
