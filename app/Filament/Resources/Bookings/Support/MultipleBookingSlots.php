<?php

namespace App\Filament\Resources\Bookings\Support;

use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\CreateMultipleBookings;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use InvalidArgumentException;

final class MultipleBookingSlots
{
    /** @return array<int, mixed> */
    public static function components(): array
    {
        return [
            Section::make(__('Даты и время записей'))
                ->description(__('Выберите несколько свободных слотов. Перед сохранением проверьте список ниже.'))
                ->schema([
                    Repeater::make('slots')
                        ->label(__('Выбранные слоты'))
                        ->schema([
                            DatePicker::make('date')
                                ->label(__('Дата'))
                                ->native()
                                ->helperText(fn (): string => __('Часовой пояс CRM: ').self::viewerTimezoneLabel().'.')
                                ->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('time', null);
                                })
                                ->required()
                                ->validationMessages(['required' => __('Укажите дату.')]),
                            Select::make('time')
                                ->label(__('Свободное время'))
                                ->options(fn (Get $get): array => self::availableTimeOptions($get))
                                ->getOptionLabelUsing(fn (mixed $value): ?string => self::timeLabel($value))
                                ->placeholder(__('Выберите свободное время'))
                                ->native(false)
                                ->disabled(fn (Get $get): bool => ! self::hasTimePrerequisites($get))
                                ->live()
                                ->required()
                                ->validationMessages(['required' => __('Выберите свободное время.')])
                                ->helperText(__('Показываются только свободные интервалы.')),
                        ])
                        ->columns(2)
                        ->defaultItems(2)
                        ->minItems(2)
                        ->maxItems(CreateMultipleBookings::MAX_SLOTS)
                        ->reorderable(false)
                        ->cloneable(false)
                        ->itemNumbers()
                        ->itemLabel(fn (array $state): string => self::slotLabel($state))
                        ->addActionLabel(__('Добавить ещё дату'))
                        ->deleteAction(fn (Action $action): Action => $action->label(__('Удалить слот'))),
                ])
                ->columns(1)
                ->columnSpanFull(),
        ];
    }

    public static function clearTimes(?Get $get, Set $set): void
    {
        if (! $get instanceof Get) {
            return;
        }

        $slots = $get('slots');
        if (! is_array($slots)) {
            return;
        }

        foreach ($slots as $key => $slot) {
            if (is_array($slot)) {
                $slot['time'] = null;
                $slots[$key] = $slot;
            }
        }

        $set('slots', $slots);
    }

    private static function hasTimePrerequisites(Get $get): bool
    {
        $format = VisitFormat::tryFrom((string) self::rootValue($get, 'visit_format'));

        return self::positiveInteger(self::rootValue($get, 'specialist_id')) !== null
            && self::positiveInteger(self::rootValue($get, 'service_id')) !== null
            && $format instanceof VisitFormat
            && self::bookingDate($get('date')) instanceof CarbonImmutable;
    }

    /** @return array<string, string> */
    private static function availableTimeOptions(Get $get): array
    {
        $actor = auth()->user();
        $specialistId = self::positiveInteger(self::rootValue($get, 'specialist_id'));
        $serviceId = self::positiveInteger(self::rootValue($get, 'service_id'));
        $format = VisitFormat::tryFrom((string) self::rootValue($get, 'visit_format'));
        $date = self::bookingDate($get('date'));

        if (! $actor instanceof User
            || $specialistId === null
            || $serviceId === null
            || ! $format instanceof VisitFormat
            || ! $date instanceof CarbonImmutable) {
            return [];
        }

        return app(BookingAvailabilityOptions::class)->forDate(
            actor: $actor,
            specialistId: $specialistId,
            serviceId: $serviceId,
            format: $format,
            date: $date,
            displayTimezone: self::viewerTimezone(),
            workingLocationId: self::positiveInteger(self::rootValue($get, 'working_location_id')),
            locationArea: self::nullableString(self::rootValue($get, 'location_area')),
        );
    }

    private static function rootValue(Get $get, string $field): mixed
    {
        return $get('../../'.$field);
    }

    /** @param array<string, mixed> $state */
    private static function slotLabel(array $state): string
    {
        $date = is_string($state['date'] ?? null) ? trim($state['date']) : '';
        $time = is_string($state['time'] ?? null) ? trim($state['time']) : '';

        if ($date === '' || $time === '') {
            return __('Новый слот');
        }

        try {
            return CarbonImmutable::parse($time)->setTimezone(self::viewerTimezone())->format('d.m.Y H:i');
        } catch (InvalidArgumentException) {
            return $date;
        }
    }

    private static function timeLabel(mixed $state): ?string
    {
        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($state)->setTimezone(self::viewerTimezone())->format('H:i');
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function bookingDate(mixed $state): ?CarbonImmutable
    {
        if ($state instanceof DateTimeInterface) {
            return CarbonImmutable::instance($state)->setTimezone(self::viewerTimezone())->startOfDay();
        }

        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        $value = trim($state);
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::viewerTimezone());
        } catch (InvalidArgumentException) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value)) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function viewerTimezone(): string
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(ResolveSpecialistViewerTimezone::class)->forUser($actor)
            : app(OrganizationContext::class)->defaultTimezone();
    }

    private static function viewerTimezoneLabel(): string
    {
        $timezone = self::viewerTimezone();

        return TimezoneOptions::label($timezone).' ('.$timezone.')';
    }
}
