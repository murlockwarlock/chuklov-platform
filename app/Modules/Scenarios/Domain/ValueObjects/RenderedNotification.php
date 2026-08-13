<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

final readonly class RenderedNotification
{
    public function __construct(
        public string $body,
        public ?string $subject,
        public string $locale,
    ) {}
}
