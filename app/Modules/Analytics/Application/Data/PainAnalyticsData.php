<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class PainAnalyticsData
{
    /** @param list<array<string, mixed>> $problems */
    public function __construct(
        public int $clientCount,
        public int $sessionCount,
        public array $problems,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client_count' => $this->clientCount,
            'session_count' => $this->sessionCount,
            'problems' => $this->problems,
        ];
    }
}
