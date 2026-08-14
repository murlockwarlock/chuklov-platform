<?php

namespace App\Filament\Support;

use App\Modules\Scheduling\Application\ScheduleMutationImpact;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;

final class ScheduleImpactPreview
{
    /**
     * @return list<Component>
     */
    public static function components(): array
    {
        return [
            Hidden::make('impact_digest')
                ->dehydrated(),
            Hidden::make('schedule_impact_bookings')
                ->dehydrated(false),
            TextEntry::make('schedule_impact_preview')
                ->label('Затронутые будущие записи')
                ->state(fn (Get $get): string => self::formatBookings($get('schedule_impact_bookings')))
                ->visible(fn (Get $get): bool => self::hasBookings($get('schedule_impact_bookings')))
                ->columnSpanFull(),
            Checkbox::make('acknowledge_impact')
                ->label('Подтверждаю влияние на будущие записи')
                ->default(false)
                ->visible(fn (Get $get): bool => self::hasBookings($get('schedule_impact_bookings')))
                ->columnSpanFull(),
        ];
    }

    /** @return array{impact_digest: string|null, schedule_impact_bookings: list<array<string, mixed>>, acknowledge_impact: bool} */
    public static function stateFromImpact(?ScheduleMutationImpact $impact): array
    {
        return [
            'impact_digest' => $impact !== null && $impact->hasConflicts() ? $impact->digest : null,
            'schedule_impact_bookings' => $impact === null ? [] : $impact->bookings,
            'acknowledge_impact' => false,
        ];
    }

    /** @return array{impact_digest: string|null, schedule_impact_bookings: list<array<string, mixed>>, acknowledge_impact: bool}|null */
    public static function stateFromValidationException(ValidationException $exception): ?array
    {
        $errors = $exception->errors();

        if (! array_key_exists('schedule_impact_digest', $errors)
            && ! array_key_exists('schedule_impact_bookings', $errors)) {
            return null;
        }

        $digest = $errors['schedule_impact_digest'][0] ?? null;
        $encodedBookings = $errors['schedule_impact_bookings'][0] ?? '[]';
        $bookings = is_string($encodedBookings) ? json_decode($encodedBookings, true) : [];

        return [
            'impact_digest' => is_string($digest) && $digest !== '' ? $digest : null,
            'schedule_impact_bookings' => is_array($bookings) ? array_values(array_filter(
                $bookings,
                static fn (mixed $booking): bool => is_array($booking),
            )) : [],
            'acknowledge_impact' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mergeValidationPreview(array $data, ValidationException $exception): array
    {
        $preview = self::stateFromValidationException($exception);

        return $preview === null ? $data : [...$data, ...$preview];
    }

    public static function applyValidationPreview(Schema $schema, ValidationException $exception): void
    {
        $preview = self::stateFromValidationException($exception);

        if ($preview === null) {
            return;
        }

        $state = $schema->getRawState();
        if ($state instanceof Arrayable) {
            $state = $state->toArray();
        }

        $schema->fill([
            ...$state,
            ...$preview,
        ]);
    }

    public static function formatBookings(mixed $bookings): string
    {
        if (! self::hasBookings($bookings)) {
            return '';
        }

        return implode("\n", array_map(
            static fn (array $booking): string => sprintf(
                '%s · %s · %s · %s · %s',
                (string) ($booking['client'] ?? 'Клиент'),
                (string) ($booking['service'] ?? 'Услуга'),
                (string) ($booking['specialist'] ?? 'Специалист'),
                self::dateLabel($booking['local_start'] ?? null),
                self::statusLabel($booking['status'] ?? null),
            ),
            array_values(array_filter($bookings, static fn (mixed $booking): bool => is_array($booking))),
        ));
    }

    private static function hasBookings(mixed $bookings): bool
    {
        return is_array($bookings) && $bookings !== [];
    }

    private static function dateLabel(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return 'Дата не указана';
        }

        return CarbonImmutable::parse($value, 'UTC')->format('d.m.Y H:i');
    }

    private static function statusLabel(mixed $status): string
    {
        $status = is_string($status) ? BookingStatus::tryFrom($status) : null;

        return match ($status) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'На рассмотрении',
            BookingStatus::Confirmed => 'Подтверждена',
            BookingStatus::Rejected => 'Отклонена',
            BookingStatus::Cancelled => 'Отменена',
            BookingStatus::Completed => 'Завершена',
            BookingStatus::NoShow => 'Не состоялась',
            default => 'Статус не указан',
        };
    }
}
