<?php

namespace App\Modules\Specialists\Domain\ValueObjects;

final readonly class SpecialistContactProfile
{
    public function __construct(
        public ?string $phone,
        public ?string $email,
        public ?string $telegram,
        public bool $phoneVisible,
        public bool $emailVisible,
        public bool $telegramVisible,
    ) {}

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'phone' => $this->phone,
            'email' => $this->email,
            'telegram' => $this->telegram,
            'phone_visible' => $this->phoneVisible,
            'email_visible' => $this->emailVisible,
            'telegram_visible' => $this->telegramVisible,
        ];
    }
}
