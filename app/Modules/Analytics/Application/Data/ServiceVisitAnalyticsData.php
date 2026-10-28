<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class ServiceVisitAnalyticsData
{
    /** @param list<array{name: string, visit_count: int, client_count: int}> $services */
    public function __construct(public int $visitCount, public array $services) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['visit_count' => $this->visitCount, 'services' => $this->services];
    }
}
