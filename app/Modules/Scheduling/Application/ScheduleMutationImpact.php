<?php

namespace App\Modules\Scheduling\Application;

final readonly class ScheduleMutationImpact
{
    /**
     * @param  list<int>  $bookingIds
     * @param  list<array<string, mixed>>  $bookings
     */
    public function __construct(
        public array $bookingIds,
        public array $bookings = [],
        public string $digest = '',
    ) {}

    public function count(): int
    {
        return count($this->bookingIds);
    }

    public function hasConflicts(): bool
    {
        return $this->bookingIds !== [];
    }

    public function summary(): string
    {
        return implode('; ', array_map(
            static fn (array $booking): string => sprintf(
                '#%s %s · %s · %s · %s',
                (string) ($booking['id'] ?? ''),
                (string) ($booking['client'] ?? 'Client'),
                (string) ($booking['service'] ?? 'Service'),
                (string) ($booking['local_start'] ?? ''),
                (string) ($booking['status'] ?? ''),
            ),
            $this->bookings,
        ));
    }

    /** @return array{count: int, bookingIds: list<int>, digest: string, bookings: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'count' => $this->count(),
            'bookingIds' => $this->bookingIds,
            'digest' => $this->digest,
            'bookings' => $this->bookings,
        ];
    }
}
