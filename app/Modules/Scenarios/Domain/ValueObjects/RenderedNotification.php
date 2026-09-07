<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use App\Modules\Channels\Domain\Enums\NotificationMessageMode;

final readonly class RenderedNotification
{
    /** @param array<string, mixed>|null $media */
    public function __construct(
        public string $body,
        public ?string $subject,
        public string $locale,
        public NotificationMessageMode $mode = NotificationMessageMode::Text,
        public bool $showCaptionAboveMedia = false,
        public ?array $media = null,
    ) {}
}
