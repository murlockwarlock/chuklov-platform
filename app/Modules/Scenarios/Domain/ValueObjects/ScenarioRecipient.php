<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

final readonly class ScenarioRecipient
{
    public function __construct(
        public string $type,
        public ?int $clientId,
        public ?int $userId,
        public string $locale,
    ) {}

    public function key(): string
    {
        return $this->type.':'.($this->clientId ?? $this->userId);
    }
}
