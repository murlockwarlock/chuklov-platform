<?php

namespace App\Modules\Specialists\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class SpecialistNotificationSettings
{
    private function __construct(
        public ?string $telegramId,
        public bool $enabled,
    ) {}

    public static function from(?string $telegramId, bool $enabled): self
    {
        $telegramId = $telegramId === null ? null : trim($telegramId);

        if ($telegramId === '') {
            $telegramId = null;
        }

        if ($telegramId !== null && preg_match('/^[0-9]{1,20}$/', $telegramId) !== 1) {
            throw new InvalidArgumentException('The specialist Telegram ID is invalid.');
        }

        return new self($telegramId, $enabled);
    }
}
