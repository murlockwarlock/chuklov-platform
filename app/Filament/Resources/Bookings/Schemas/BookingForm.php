<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Filament\Resources\Bookings\Support\BookingAvailabilityOptions;
use App\Filament\Resources\Bookings\Support\MultipleBookingSlots;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Application\CreateClient as CreateClientAction;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Application\BookingLocationResolver;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class BookingForm
{
    public static function configure(Schema $schema, bool $multiple = false): Schema
    {
        return $schema->components([
            ...self::sharedComponents($multiple),
            ...($multiple ? MultipleBookingSlots::components() : self::singleComponents()),
        ]);
    }

    /** @return array<int, mixed> */
    private static function sharedComponents(bool $multiple): array
    {
        return [
            Select::make('specialist_id')
                ->label(__('Специалист'))
                ->options(fn (): array => Specialist::query()
                    ->where('organization_id', app(OrganizationContext::class)->id())
                    ->where('is_active', true)
                    ->orderBy('display_name')
                    ->orderBy('id')
                    ->pluck('display_name', 'id')
                    ->all())
                ->searchable()
                ->required()
                ->validationMessages(['required' => __('Выберите специалиста.')])
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set) use ($multiple): void {
                    self::clearAvailableTime($set, $get, $multiple);

                    $serviceId = (int) $get('service_id');
                    $specialistId = (int) $get('specialist_id');
                    if ($serviceId === 0 || $specialistId === 0) {
                        return;
                    }

                    $isAssigned = SpecialistServiceAssignment::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->where('specialist_id', $specialistId)
                        ->where('service_id', $serviceId)
                        ->exists();
                    if (! $isAssigned) {
                        $set('service_id', null);
                    }
                }),
            Select::make('service_id')
                ->label(__('Услуга'))
                ->options(fn (Get $get): array => Service::query()
                    ->where('organization_id', app(OrganizationContext::class)->id())
                    ->where('is_active', true)
                    ->where('catalog_type', CatalogItemType::Service->value)
                    ->when((int) $get('specialist_id') > 0, fn (Builder $query): Builder => $query->whereIn(
                        'id',
                        SpecialistServiceAssignment::query()
                            ->where('organization_id', app(OrganizationContext::class)->id())
                            ->where('specialist_id', (int) $get('specialist_id'))
                            ->select('service_id'),
                    ))
                    ->orderBy('name')
                    ->orderBy('id')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required()
                ->validationMessages(['required' => __('Выберите услугу.')])
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set) use ($multiple): void {
                    self::preservePrefilledTimeOrClear($get, $set, $multiple);
                }),
            Select::make('client_id')
                ->label(__('Клиент'))
                ->options([])
                ->searchable()
                ->getSearchResultsUsing(function (string $search): array {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return [];
                    }

                    $clients = app(ClientSearch::class);

                    return $clients->formatOptionLabels(
                        $clients->withVerifiedTelegramIdentity(
                            $clients->query($actor, $search),
                        )
                            ->orderBy('full_name')
                            ->orderBy('id')
                            ->limit(ClientSearch::MAX_RESULTS)
                            ->get(['id', 'full_name', 'email', 'phone']),
                    );
                })
                ->getOptionLabelUsing(function (mixed $value): ?string {
                    $actor = auth()->user();

                    return $actor instanceof User
                        ? app(ClientSearch::class)->optionLabel($actor, $value)
                        : null;
                })
                ->afterStateUpdated(function (Get $get, Set $set) use ($multiple): void {
                    if (! $multiple) {
                        $selectedTime = self::selectedBookingTime($get);
                        if ($selectedTime !== null) {
                            $set('booking_time', $selectedTime);
                            $set('starts_at', $selectedTime);
                        }
                    }
                })
                ->required()
                ->validationMessages(['required' => __('Выберите клиента.')])
                ->helperText(fn (): string => self::canCreateClient()
                    ? __('Найдите клиента по имени, телефону, Telegram или email. Если его ещё нет в базе — добавьте нового.')
                    : __('Выберите клиента из списка.'))
                ->createOptionModalHeading(__('Добавить клиента'))
                ->createOptionAction(fn (Action $action): Action => $action
                    ->label(__('Добавить нового клиента'))
                    ->button()
                    ->icon(Heroicon::Plus)
                    ->extraAttributes([
                        'data-testid' => 'booking-create-client',
                        'wire:loading.attr' => 'disabled',
                    ])
                    ->visible(fn (): bool => self::canCreateClient()))
                ->createOptionForm([
                    TextInput::make('full_name')
                        ->label(__('Имя и фамилия'))
                        ->required()
                        ->validationMessages(['required' => __('Укажите имя клиента.')])
                        ->maxLength(160),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(320),
                    TextInput::make('phone')
                        ->label(__('Телефон'))
                        ->tel()
                        ->maxLength(32),
                    TextInput::make('lead_source')
                        ->label(__('Источник клиента'))
                        ->placeholder(__('Например: Telegram, Instagram, рекомендация'))
                        ->maxLength(120),
                ])
                ->createOptionUsing(function (array $data, ?Get $get = null, ?Set $set = null) use ($multiple): int {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    $selectedTime = ! $multiple && $get instanceof Get ? self::selectedBookingTime($get) : null;

                    try {
                        $phone = trim((string) ($data['phone'] ?? ''));
                        $client = app(CreateClientAction::class)->handle(
                            actor: $actor,
                            fullName: (string) $data['full_name'],
                            email: isset($data['email']) && trim((string) $data['email']) !== '' ? (string) $data['email'] : null,
                            phone: $phone === '' ? null : $phone,
                            language: (string) config('portal.default_locale', 'ru'),
                            timezone: app(OrganizationContext::class)->defaultTimezone(),
                            leadSource: isset($data['lead_source']) && trim((string) $data['lead_source']) !== '' ? (string) $data['lead_source'] : null,
                        );

                        if ($selectedTime !== null && $set instanceof Set) {
                            $set('booking_time_snapshot', $selectedTime);
                            $set('booking_time', $selectedTime);
                            $set('starts_at', $selectedTime);
                        }

                        return (int) $client->getKey();
                    } catch (ValidationException $exception) {
                        throw $exception;
                    } catch (Throwable $exception) {
                        report($exception);

                        throw ValidationException::withMessages([
                            'full_name' => __('Не удалось добавить клиента. Проверьте данные и попробуйте ещё раз.'),
                        ]);
                    }
                }),
            Select::make('visit_format')
                ->label(__('Формат визита'))
                ->options([
                    VisitFormat::Office->value => __('В клинике'),
                    VisitFormat::HomeVisit->value => __('Выезд на дом'),
                    VisitFormat::Online->value => __('Онлайн'),
                ])
                ->required()
                ->validationMessages(['required' => __('Выберите формат визита.')])
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($multiple): void {
                    self::preservePrefilledTimeOrClear($get, $set, $multiple);
                    if (self::positiveInteger($get('party_size')) === null) {
                        $set('party_size', 1);
                    }

                    if ($state === VisitFormat::Office->value) {
                        $location = WorkingLocation::query()
                            ->where('organization_id', app(OrganizationContext::class)->id())
                            ->where('is_active', true)
                            ->orderByDesc('is_default_office')
                            ->orderBy('name')
                            ->first();
                        $set('working_location_id', $location?->getKey());
                        $set('location', $location->address ?? app(OrganizationContext::class)->organization()->settings()->where('setting_key', 'office_location')->value('string_value'));
                    }
                    if ($state === VisitFormat::Online->value) {
                        $set('location', null);
                        $set('working_location_id', null);
                    }

                    $set('booking_time_prefilled', false);
                }),
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
                ->searchable()
                ->nullable()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($multiple): void {
                    self::preservePrefilledTimeOrClear($get, $set, $multiple);
                    $location = $state === null || $state === ''
                        ? null
                        : WorkingLocation::query()
                            ->where('organization_id', app(OrganizationContext::class)->id())
                            ->whereKey((int) $state)
                            ->first();
                    $set('location', $location?->address);
                })
                ->visible(fn (Get $get): bool => $get('visit_format') === VisitFormat::Office->value)
                ->helperText(__('Время доступности рассчитывается по часовому поясу выбранной локации.')),
            TextInput::make('location_area')
                ->label(__('Район выезда'))
                ->maxLength(160)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) use ($multiple): void {
                    self::preservePrefilledTimeOrClear($get, $set, $multiple);
                })
                ->visible(fn (Get $get): bool => $get('visit_format') === VisitFormat::HomeVisit->value),
            TextInput::make('party_size')
                ->label(__('Количество человек'))
                ->default(1)
                ->integer()
                ->minValue(1)
                ->maxValue(20)
                ->required()
                ->validationMessages(['required' => __('Укажите количество человек.')])
                ->helperText(__('Укажите, сколько человек придут на одну запись.')),
            TextInput::make('location')
                ->label(fn (Get $get): string => $get('visit_format') === VisitFormat::Office->value ? __('Адрес приёма') : __('Адрес выезда'))
                ->default(fn (Get $get): ?string => $get('visit_format') === VisitFormat::Office->value
                    ? app(OrganizationContext::class)->organization()->settings()->where('setting_key', 'office_location')->value('string_value')
                    : null)
                ->required(fn (Get $get): bool => $get('visit_format') === VisitFormat::HomeVisit->value)
                ->validationMessages(['required' => __('Укажите адрес выезда.')])
                ->helperText(fn (Get $get): string => $get('visit_format') === VisitFormat::Office->value
                    ? __('Можно изменить адрес только для этой записи.')
                    : __('Укажите место выезда для этой записи.'))
                ->maxLength(500)
                ->visible(fn (Get $get): bool => in_array($get('visit_format'), [VisitFormat::Office->value, VisitFormat::HomeVisit->value], true)),
        ];
    }

    /** @return array<int, mixed> */
    private static function singleComponents(): array
    {
        return [
            DatePicker::make('booking_date')
                ->label(__('Дата'))
                ->native()
                ->helperText(fn (): string => __('Часовой пояс CRM: ').self::viewerTimezoneLabel().'.')
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    self::clearAvailableTime($set);
                })
                ->required(fn (Get $get): bool => self::startsAt($get('starts_at')) === null)
                ->validationMessages(['required' => __('Укажите дату и время записи.')]),
            Select::make('booking_time')
                ->label(__('Доступное время'))
                ->options(fn (Get $get): array => self::availableTimeOptions($get))
                ->placeholder(__('Выберите доступное время'))
                ->native(false)
                ->disabled(fn (Get $get): bool => ! self::hasAvailableTimePrerequisites($get))
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $selectedTime = is_string($state) && trim($state) !== '' ? $state : null;
                    $set('booking_time_snapshot', $selectedTime);
                    $set('starts_at', $selectedTime);
                    $set('blocking_ends_at', null);
                    $set('confirm_backdated', false);
                    $set('booking_time_prefilled', false);
                })
                ->required(fn (Get $get): bool => self::startsAt($get('starts_at')) === null)
                ->validationMessages(['required' => __('Выберите доступное время.')])
                ->helperText(fn (Get $get): string => self::availableTimeHelper($get)),
            Hidden::make('booking_time_prefilled')
                ->default(false)
                ->dehydrated(false),
            Hidden::make('booking_time_snapshot')
                ->dehydrated(false),
            Hidden::make('starts_at')
                ->hidden()
                ->dehydratedWhenHidden()
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('blocking_ends_at', null);
                    $set('confirm_backdated', false);
                })
                ->required()
                ->validationMessages(['required' => __('Укажите дату и время записи.')]),
            DateTimePicker::make('blocking_ends_at')
                ->label(__('Занять время до'))
                ->seconds(false)
                ->timezone(fn (): string => self::viewerTimezone())
                ->nullable()
                ->disabled(fn (Get $get): bool => self::startsAt($get('starts_at')) === null)
                ->helperText(__('Оставьте пустым, чтобы занять обычное время услуги.')),
            TextEntry::make('backdated_warning')
                ->label(__('Внимание'))
                ->state(fn (Get $get): string => self::backdatedWarning($get))
                ->visible(fn (Get $get): bool => self::isBackdated($get))
                ->columnSpanFull(),
            Checkbox::make('confirm_backdated')
                ->label(__('Подтверждаю создание записи задним числом'))
                ->default(false)
                ->accepted(fn (Get $get): bool => self::isBackdated($get))
                ->validationMessages([
                    'accepted' => __('Подтвердите создание записи задним числом.'),
                ])
                ->visible(fn (Get $get): bool => self::isBackdated($get))
                ->columnSpanFull(),
        ];
    }

    private static function canCreateClient(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ManageClients,
            );
    }

    private static function clearAvailableTime(Set $set, ?Get $get = null, bool $multiple = false): void
    {
        if ($multiple) {
            MultipleBookingSlots::clearTimes($get, $set);

            return;
        }

        $set('booking_time', null);
        $set('booking_time_snapshot', null);
        $set('starts_at', null);
        $set('blocking_ends_at', null);
        $set('confirm_backdated', false);
        $set('booking_time_prefilled', false);
    }

    private static function selectedBookingTime(Get $get): ?string
    {
        foreach (['booking_time_snapshot', 'booking_time', 'starts_at'] as $field) {
            $value = $get($field);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function preservePrefilledTimeOrClear(Get $get, Set $set, bool $multiple = false): void
    {
        if ($multiple) {
            MultipleBookingSlots::clearTimes($get, $set);

            return;
        }

        if ($get('booking_time_prefilled') === true
            || ($get('booking_date') === null && $get('booking_time') === null)) {
            return;
        }

        self::clearAvailableTime($set);
    }

    private static function hasAvailableTimePrerequisites(Get $get): bool
    {
        $format = VisitFormat::tryFrom((string) $get('visit_format'));

        return self::positiveInteger($get('specialist_id')) !== null
            && self::positiveInteger($get('service_id')) !== null
            && $format instanceof VisitFormat
            && self::bookingDate($get('booking_date')) instanceof CarbonImmutable;
    }

    private static function availableTimeHelper(Get $get): string
    {
        if (self::positiveInteger($get('specialist_id')) === null
            || self::positiveInteger($get('service_id')) === null) {
            return __('Сначала выберите специалиста и услугу.');
        }

        $format = VisitFormat::tryFrom((string) $get('visit_format'));
        if (! $format instanceof VisitFormat) {
            return __('Сначала выберите формат визита.');
        }

        if (! (self::bookingDate($get('booking_date')) instanceof CarbonImmutable)) {
            return __('Сначала выберите дату.');
        }

        return __('Показываются только свободные интервалы в часовом поясе CRM. Если список пуст, на эту дату свободного времени нет.');
    }

    /** @return array<string, string> */
    private static function availableTimeOptions(Get $get): array
    {
        $actor = auth()->user();
        $specialistId = self::positiveInteger($get('specialist_id'));
        $serviceId = self::positiveInteger($get('service_id'));
        $format = VisitFormat::tryFrom((string) $get('visit_format'));
        $date = self::bookingDate($get('booking_date'));

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
            workingLocationId: self::positiveInteger($get('working_location_id')),
            locationArea: self::nullableString($get('location_area')),
            allowHistorical: true,
        );
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

    private static function isBackdated(Get $get): bool
    {
        $startsAt = self::startsAt($get('starts_at'));

        return $startsAt instanceof CarbonImmutable && $startsAt->lessThan(CarbonImmutable::now('UTC'));
    }

    private static function backdatedWarning(Get $get): string
    {
        $startsAt = self::startsAt($get('starts_at'));
        if (! $startsAt instanceof CarbonImmutable) {
            return '';
        }

        $timezone = self::scheduleTimezone($get, $startsAt);

        return __('Вы создаёте запись задним числом: ').$startsAt->setTimezone($timezone)->format('d.m.Y H:i').'.';
    }

    private static function startsAt(mixed $state): ?CarbonImmutable
    {
        if ($state instanceof DateTimeInterface) {
            return CarbonImmutable::instance($state)->utc();
        }

        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($state, self::viewerTimezone())->utc();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function scheduleTimezone(Get $get, CarbonImmutable $startsAt): string
    {
        $organization = app(OrganizationContext::class)->organization();
        $specialist = Specialist::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey((int) $get('specialist_id'))
            ->first();
        $format = VisitFormat::tryFrom((string) $get('visit_format'));

        if (! $specialist instanceof Specialist || ! $format instanceof VisitFormat) {
            return $specialist?->timezone ?? $organization->defaultTimezone();
        }

        try {
            $workingLocationId = is_numeric($get('working_location_id'))
                ? (int) $get('working_location_id')
                : null;
            $resolver = app(BookingLocationResolver::class);
            $selection = $resolver->selection(
                format: $format,
                workingLocationId: $workingLocationId,
                areaName: is_string($get('location_area')) ? $get('location_area') : null,
                startsAt: $startsAt,
            );

            return $resolver->scheduleTimezone($specialist, $format, $selection);
        } catch (InvalidArgumentException|ValidationException) {
            return $specialist->timezone ?? $organization->defaultTimezone();
        }
    }
}
