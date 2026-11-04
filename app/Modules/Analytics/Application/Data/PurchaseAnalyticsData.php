<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class PurchaseAnalyticsData
{
    /** @param list<array{key: string, label: string, count: int, client_count: int}> $types */
    public function __construct(public array $types) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['types' => $this->types];
    }
}
