<?php

namespace App\Filament\Resources\Bookings\Actions;

use App\Filament\Resources\Bookings\Support\BookingAvailabilityOptions;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Application\ApproveHomeVisitBooking;
use App\Modules\Scheduling\Application\CancelBooking;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Application\MarkBookingNoShow;
use App\Modules\Scheduling\Application\RejectHomeVisitBooking;
use App\Modules\Scheduling\Application\RescheduleBooking;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Application\SetOnlineMeetingUrl;
use App\Modules\Scheduling\Application\UpdateBookingBlockingInterval;
use App\Modules\Scheduling\Application\UpdateBookingPartySize;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PaymentRequirementType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BookingLifecycleActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        $actor = auth()->user();
        $canManageScheduling = $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageScheduling,
        );

        return [
            Action::make('confirm')
                ->label(__('Подтвердить запись'))
                ->color('success')
                ->icon('heroicon-o-check')
                ->schema([Textarea::make('reason')->label(__('Комментарий'))->maxLength(500)])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && $record->status === BookingStatus::Requested
                    && in_array($record->visit_format, [VisitFormat::Office, VisitFormat::Online], true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(ConfirmBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                        $record->refresh();
                        Notification::make()->success()->title(__('Запись подтверждена'))->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    } catch (Throwable $exception) {
                        self::sendUnexpectedErrorNotification($exception);
                    }
                }),

            Action::make('approveHomeVisit')
                ->label(__('Подтвердить выезд'))
                ->color('success')
                ->icon('heroicon-o-truck')
                ->requiresConfirmation()
                ->modalDescription(__('Выезд будет подтверждён, а выбранное условие оплаты сохранится в записи.'))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('Комментарий'))
                        ->maxLength(500),
                    Select::make('payment_requirement')
                        ->label(__('Условие оплаты'))
                        ->options([
                            PaymentRequirementType::FullPayment->value => __('Полная оплата'),
                            PaymentRequirementType::TransportDeposit->value => __('Депозит за выезд'),
                        ])
                        ->nullable(),
                ])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && $record->status === BookingStatus::PendingReview
                    && $record->visit_format === VisitFormat::HomeVisit)
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(ApproveHomeVisitBooking::class)->handle(
                            $actor,
                            $record,
                            $data['reason'] ?? null,
                            $data['payment_requirement'] ?? null,
                        );
                        $record->refresh();
                        Notification::make()->success()->title(__('Выезд подтверждён'))->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    } catch (Throwable $exception) {
                        self::sendUnexpectedErrorNotification($exception);
                    }
                }),

            Action::make('rejectHomeVisit')
                ->label(__('Отклонить заявку'))
                ->color('danger')
                ->icon('heroicon-o-x-mark')
                ->requiresConfirmation()
                ->modalDescription(__('Заявка будет отклонена и перестанет участвовать в работе.'))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('Причина отказа'))
                        ->required()
                        ->maxLength(500),
                ])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && $record->status === BookingStatus::PendingReview
                    && $record->visit_format === VisitFormat::HomeVisit)
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(RejectHomeVisitBooking::class)->handle($actor, $record, (string) $data['reason']);
                        $record->refresh();
                        Notification::make()->success()->title(__('Заявка на выезд отклонена'))->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    } catch (Throwable $exception) {
                        self::sendUnexpectedErrorNotification($exception);
                    }
                }),

            Action::make('adjustBlockingInterval')
                ->label(__('Изменить время занятости'))
                ->icon('heroicon-o-clock')
                ->modalDescription(__('Укажите, до какого времени специалист должен быть занят в календаре. Длительность услуги не изменится.'))
                ->schema([
                    DateTimePicker::make('blocking_ends_at')
                        ->label(__('Занять время до'))
                        ->default(fn (Booking $record): CarbonImmutable => $record->blockingEndsAtUtc()->setTimezone(self::viewerTimezone()))
                        ->timezone(fn (): string => self::viewerTimezone())
                        ->seconds(false)
                        ->required(),
                    Hidden::make('expected_event_version')
                        ->default(fn (Booking $record): int => $record->event_version)
                        ->required(),
                ])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && in_array($record->status->value, BookingStatus::blockingValues(), true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        $blockingEndsAt = $data['blocking_ends_at'] instanceof DateTimeInterface
                            ? $data['blocking_ends_at']
                            : CarbonImmutable::parse((string) $data['blocking_ends_at'], self::viewerTimezone());
                        app(UpdateBookingBlockingInterval::class)->handle(
                            actor: $actor,
                            booking: $record,
                            blockingEndsAt: $blockingEndsAt,
                            expectedEventVersion: (int) $data['expected_event_version'],
                        );
                        $record->refresh();
                        Notification::make()->success()->title(__('Время занятости в календаре обновлено'))->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    } catch (Throwable $exception) {
                        self::sendUnexpectedErrorNotification($exception);
                    }
                }),

            Action::make('adjustPartySize')
                ->label(__('Изменить количество человек'))
                ->icon('heroicon-o-user-group')
                ->modalDescription(__('Количество человек относится к одной записи и не меняет стоимость услуги.'))
                ->schema([
                    TextInput::make('party_size')
                        ->label(__('Количество человек'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue((int) config('scheduling.max_party_size', 20))
                        ->default(fn (Booking $record): int => (int) $record->party_size)
                        ->required(),
                    Hidden::make('expected_event_version')
                        ->default(fn (Booking $record): int => $record->event_version)
                        ->required(),
                ])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && ! in_array($record->status->value, BookingStatus::terminalValues(), true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(UpdateBookingPartySize::class)->handle(
                            actor: $actor,
                            booking: $record,
                            partySize: (int) $data['party_size'],
                            expectedEventVersion: (int) $data['expected_event_version'],
                        );
                        $record->refresh();
                        Notification::make()->success()->title(__('Количество человек обновлено'))->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    } catch (Throwable $exception) {
                        self::sendUnexpectedErrorNotification($exception);
                    }
                }),

            Action::make('reschedule')
                ->label(__('Перенести'))
                ->icon('heroicon-o-calendar')
                ->modalDescription(__('Выберите новую дату, затем свободный интервал. Время показано в часовом поясе CRM.'))
                ->schema([
                    Select::make('working_location_id')
                        ->label(__('Локация'))
                        ->options(fn (): array => WorkingLocation::query()
                            ->where('organization_id', app(OrganizationContext::class)->id())
                            ->where('is_active', true)
                            ->orderByDesc('is_default_office')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (WorkingLocation $location): array => [
                                $location->getKey() => $location->name.' — '.$location->address,
                            ])
                            ->all())
                        ->default(fn (Booking $record): ?int => $record->working_location_id)
                        ->searchable()
                        ->nullable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            self::clearRescheduleTime($set);
                            $location = $state === null || $state === ''
                                ? null
                                : WorkingLocation::query()
                                    ->where('organization_id', app(OrganizationContext::class)->id())
                                    ->whereKey((int) $state)
                                    ->first();
                            $set('location', $location?->address);
                        })
                        ->visible(fn (Booking $record): bool => $record->visit_format === VisitFormat::Office),
                    TextInput::make('location_area')
                        ->label(__('Район выезда'))
                        ->default(fn (Booking $record): ?string => $record->location_area)
                        ->maxLength(160)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set): void {
                            self::clearRescheduleTime($set);
                        })
                        ->visible(fn (Booking $record): bool => $record->visit_format === VisitFormat::HomeVisit),
                    TextInput::make('location')
                        ->label(fn (Booking $record): string => $record->visit_format === VisitFormat::Office ? __('Адрес приёма') : __('Адрес выезда'))
                        ->default(fn (Booking $record): ?string => $record->location)
                        ->visible(fn (Booking $record): bool => in_array($record->visit_format, [VisitFormat::Office, VisitFormat::HomeVisit], true))
                        ->maxLength(500),
                    DatePicker::make('booking_date')
                        ->label(__('Новая дата'))
                        ->default(fn (Booking $record): string => $record->startsAtUtc()
                            ->setTimezone(self::viewerTimezone())
                            ->toDateString())
                        ->native()
                        ->live()
                        ->helperText(fn (): string => __('Часовой пояс CRM: ').self::viewerTimezoneLabel().'.')
                        ->afterStateUpdated(function (Set $set): void {
                            self::clearRescheduleTime($set);
                        })
                        ->required(),
                    Select::make('booking_time')
                        ->label(__('Доступное время'))
                        ->options(fn (Get $get, Booking $record): array => self::availableTimeOptions($get, $record))
                        ->placeholder(__('Выберите доступное время'))
                        ->native(false)
                        ->disabled(fn (Get $get): bool => self::rescheduleDate($get('booking_date')) === null)
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $set('starts_at', is_string($state) && trim($state) !== '' ? $state : null);
                        })
                        ->required()
                        ->helperText(fn (Get $get): string => self::availableTimeHelper($get('booking_date'))),
                    Hidden::make('starts_at')
                        ->dehydratedWhenHidden()
                        ->required(),
                    Hidden::make('expected_event_version')
                        ->default(fn (Booking $record): int => $record->event_version)
                        ->required(),
                    Textarea::make('reason')->label(__('Причина'))->maxLength(500),
                ])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && ! in_array($record->status->value, BookingStatus::terminalValues(), true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        $startsAt = $data['starts_at'] instanceof DateTimeInterface
                            ? $data['starts_at']
                            : CarbonImmutable::parse((string) $data['starts_at'], (string) config('app.timezone'));
                        app(RescheduleBooking::class)->handle(
                            actor: $actor,
                            booking: $record,
                            newStartsAt: $startsAt,
                            clientTimezone: null,
                            reason: $data['reason'] ?? null,
                            expectedEventVersion: (int) $data['expected_event_version'],
                            location: array_key_exists('location', $data) ? (string) $data['location'] : null,
                            workingLocationId: isset($data['working_location_id']) && $data['working_location_id'] !== ''
                                ? (int) $data['working_location_id']
                                : null,
                            locationArea: isset($data['location_area']) ? (string) $data['location_area'] : null,
                        );
                        $record->refresh();
                        Notification::make()->success()->title(__('Запись успешно перенесена'))->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    } catch (Throwable $exception) {
                        self::sendUnexpectedErrorNotification($exception);
                    }
                }),

            Action::make('complete')
                ->label(__('Завершить визит'))
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalDescription(__('Визит будет переведён в завершённое состояние. Проверьте клиента и время.'))
                ->schema([Textarea::make('reason')->label(__('Комментарий'))->maxLength(500)])
                ->visible(fn (Booking $record): bool => $canManageScheduling && $record->status === BookingStatus::Confirmed)
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(CompleteBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                        $record->refresh();
                        Notification::make()->success()->title(__('Визит успешно завершён'))->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    } catch (Throwable $exception) {
                        self::sendUnexpectedErrorNotification($exception);
                    }
                }),

            Action::make('noShow')
                ->label(__('Отметить неявку'))
                ->color('danger')
                ->icon('heroicon-o-user-minus')
                ->requiresConfirmation()
                ->modalDescription(__('Запись будет отмечена как не состоявшаяся. Проверьте клиента и время.'))
                ->schema([Textarea::make('reason')->label(__('Комментарий'))->maxLength(500)])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && in_array($record->status, [BookingStatus::Requested, BookingStatus::Confirmed], true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(MarkBookingNoShow::class)->handle($actor, $record, $data['reason'] ?? null);
                        $record->refresh();
                        Notification::make()->success()->title(__('Запись отмечена как не состоявшаяся'))->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    } catch (Throwable $exception) {
                        self::sendUnexpectedErrorNotification($exception);
                    }
                }),

            Action::make('meetingUrl')
                ->label(__('Ссылка на встречу'))
                ->icon('heroicon-o-video-camera')
                ->schema([
                    TextInput::make('meeting_url')->label(__('Ссылка на встречу'))->url()->required()->maxLength(2000),
                    Textarea::make('reason')->label(__('Комментарий'))->maxLength(500),
                ])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && $record->visit_format === VisitFormat::Online
                    && $record->meeting_link_mode?->value === 'manual'
                    && in_array($record->status, [BookingStatus::Requested, BookingStatus::Confirmed], true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(SetOnlineMeetingUrl::class)->handle($actor, $record, (string) $data['meeting_url'], $data['reason'] ?? null);
                        $record->refresh();
                        Notification::make()->success()->title(__('Ссылка на встречу обновлена'))->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    } catch (Throwable $exception) {
                        self::sendUnexpectedErrorNotification($exception);
                    }
                }),

            Action::make('cancel')
                ->label(__('Отменить'))
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                ->modalDescription(__('Запись будет отменена и перестанет участвовать в расписании. Проверьте выбранную запись.'))
                ->schema([Textarea::make('reason')->label(__('Причина'))->maxLength(500)])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && ! in_array($record->status->value, BookingStatus::terminalValues(), true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(CancelBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                        $record->refresh();
                        Notification::make()->success()->title(__('Запись отменена'))->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    } catch (Throwable $exception) {
                        self::sendUnexpectedErrorNotification($exception);
                    }
                }),
        ];
    }

    private static function sendErrorNotification(ValidationException $exception): void
    {
        $message = collect($exception->errors())->flatten()->first();
        if (! is_string($message) || $message === '') {
            $message = __('Не удалось выполнить действие.');
        }

        Notification::make()
            ->danger()
            ->title(__('Действие отклонено'))
            ->body($message)
            ->send();
    }

    private static function sendUnexpectedErrorNotification(Throwable $exception): void
    {
        report($exception);

        Notification::make()
            ->danger()
            ->title(__('Не удалось выполнить действие'))
            ->body(__('Данные не изменены. Обновите страницу и попробуйте ещё раз.'))
            ->send();
    }

    private static function viewerTimezone(): string
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(ResolveSpecialistViewerTimezone::class)->forUser($actor)
            : app(OrganizationContext::class)->defaultTimezone();
    }

    /** @return array<string, string> */
    private static function availableTimeOptions(Get $get, Booking $record): array
    {
        $actor = auth()->user();
        $date = self::rescheduleDate($get('booking_date'));

        if (! $actor instanceof User || ! $date instanceof CarbonImmutable) {
            return [];
        }

        return app(BookingAvailabilityOptions::class)->forDate(
            actor: $actor,
            specialistId: (int) $record->specialist_id,
            serviceId: (int) $record->service_id,
            format: $record->visit_format,
            date: $date,
            displayTimezone: self::viewerTimezone(),
            workingLocationId: self::positiveInteger($get('working_location_id')),
            locationArea: self::nullableString($get('location_area')),
            ignoreBookingId: (int) $record->getKey(),
        );
    }

    private static function clearRescheduleTime(Set $set): void
    {
        $set('booking_time', null);
        $set('starts_at', null);
    }

    private static function availableTimeHelper(mixed $state): string
    {
        return self::rescheduleDate($state) instanceof CarbonImmutable
            ? __('Показываются только свободные интервалы в часовом поясе CRM. Если список пуст, на эту дату свободного времени нет.')
            : __('Сначала выберите дату.');
    }

    private static function rescheduleDate(mixed $state): ?CarbonImmutable
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
        } catch (\InvalidArgumentException) {
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

    private static function viewerTimezoneLabel(): string
    {
        $timezone = self::viewerTimezone();

        return TimezoneOptions::label($timezone).' ('.$timezone.')';
    }
}
