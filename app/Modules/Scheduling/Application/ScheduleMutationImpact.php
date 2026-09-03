<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use Carbon\CarbonImmutable;

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
                '%s · %s · %s · %s',
                (string) ($booking['client'] ?? 'Client'),
                (string) ($booking['service'] ?? 'Service'),
                self::dateLabel($booking['local_start'] ?? null),
                self::statusLabel($booking['status'] ?? null),
            ),
            $this->bookings,
        ));
    }

    private static function dateLabel(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return 'дата не указана';
        }

        return CarbonImmutable::parse($value)->format('d.m.Y H:i');
    }

    private static function statusLabel(mixed $status): string
    {
        $status = is_string($status) ? BookingStatus::tryFrom($status) : null;

        return match ($status) {
            BookingStatus::Requested => 'ожидает подтверждения',
            BookingStatus::PendingReview => 'на рассмотрении',
            BookingStatus::Confirmed => 'подтверждена',
            BookingStatus::Rejected => 'отклонена',
            BookingStatus::Cancelled => 'отменена',
            BookingStatus::Completed => 'завершена',
            BookingStatus::NoShow => 'не состоялась',
            default => 'статус не указан',
        };
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
