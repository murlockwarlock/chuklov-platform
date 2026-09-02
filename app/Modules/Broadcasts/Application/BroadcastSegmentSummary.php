<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;

final class BroadcastSegmentSummary
{
    /** @param list<array{key: string, operator: string, value: mixed}> $filters */
    public function make(array $filters): string
    {
        if ($filters === []) {
            return 'Все подходящие клиенты организации';
        }

        return 'Будут выбраны клиенты, у которых '.collect($filters)
            ->map(fn (array $filter): string => $this->condition($filter))
            ->implode(' и ').'.';
    }

    /** @param array{key: string, operator: string, value: mixed} $filter */
    private function condition(array $filter): string
    {
        $label = mb_strtolower(SegmentDefinition::labels()[$filter['key']] ?? 'условие');
        $value = $this->value($filter['key'], $filter['value']);

        return match ($filter['operator']) {
            'equals' => $label.' — '.$value,
            'in' => $label.' — одно из: '.$value,
            'gte' => $label.' — не меньше '.$value,
            'before' => $label.' — раньше '.$value,
            'after' => $label.' — позже '.$value,
            default => $label.' — '.$value,
        };
    }

    private function value(string $key, mixed $value): string
    {
        $values = is_array($value) ? $value : [$value];

        return collect($values)->map(fn (mixed $item): string => match ($key) {
            'b2b_specialist_answer' => match (B2bSpecialistAnswer::tryFrom((string) $item)) {
                B2bSpecialistAnswer::Yes => 'Да',
                B2bSpecialistAnswer::No => 'Нет',
                default => (string) $item,
            },
            'booking_status' => $this->bookingStatusLabel($item),
            'language' => match ((string) $item) {
                'ru' => 'Русский',
                'en' => 'Английский',
                default => (string) $item,
            },
            'verified_channel' => (string) $item === 'telegram' ? 'Telegram' : (string) $item,
            default => $item === true ? 'Да' : ($item === false ? 'Нет' : (string) $item),
        })->implode(', ');
    }

    private function bookingStatusLabel(mixed $status): string
    {
        return match (BookingStatus::tryFrom((string) $status)) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'На рассмотрении',
            BookingStatus::Confirmed => 'Подтверждена',
            BookingStatus::Rejected => 'Отклонена',
            BookingStatus::Cancelled => 'Отменена',
            BookingStatus::Completed => 'Завершена',
            BookingStatus::NoShow => 'Не состоялась',
            default => (string) $status,
        };
    }
}
