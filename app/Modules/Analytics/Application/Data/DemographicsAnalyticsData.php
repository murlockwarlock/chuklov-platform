<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class DemographicsAnalyticsData
{
    /** @param list<array{label: string, count: int, percent: float}> $age
     * @param  list<array{label: string, count: int, percent: float}>  $sex
     * @param  list<array{label: string, count: int, percent: float}>  $country
     */
    public function __construct(
        public int $clientCount,
        public array $age,
        public array $sex,
        public array $country,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client_count' => $this->clientCount,
            'age' => $this->age,
            'sex' => $this->sex,
            'country' => $this->country,
        ];
    }
}
