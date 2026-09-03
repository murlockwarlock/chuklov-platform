<?php

namespace App\Modules\Channels\Domain\ValueObjects;

final readonly class NotificationMedia
{
    public function __construct(
        public string $type,
        public ?string $url = null,
        public mixed $stream = null,
        public ?string $fileName = null,
    ) {}
}
