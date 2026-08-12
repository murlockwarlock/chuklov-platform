<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Scheduling\Domain\ValueObjects\AvailabilitySlot;

final readonly class AvailabilityResult
{
    /** @param list<AvailabilitySlot> $slots */
    public function __construct(
        public int $specialistId,
        public int $serviceId,
        public string $scheduleTimezone,
        public string $displayTimezone,
        public array $slots,
    ) {}

    /** @return array{specialistId: int, serviceId: int, scheduleTimezone: string, displayTimezone: string, slots: list<array<string, string>>} */
    public function toArray(): array
    {
        return [
            'specialistId' => $this->specialistId,
            'serviceId' => $this->serviceId,
            'scheduleTimezone' => $this->scheduleTimezone,
            'displayTimezone' => $this->displayTimezone,
            'slots' => array_map(
                fn (AvailabilitySlot $slot): array => $slot->toArray(),
                $this->slots,
            ),
        ];
    }
}
