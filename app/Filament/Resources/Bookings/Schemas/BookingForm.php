<?php

namespace App\Filament\Resources\Bookings\Schemas;

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
use Filament\Forms\Components\DateTimePicker;
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

class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                    ->afterStateUpdated(function (Get $get, Set $set): void {
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
                    ->live(),
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
                        ->extraAttributes(['data-testid' => 'booking-create-client'])
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
                    ->createOptionUsing(function (array $data): int {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
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

                        return (int) $client->getKey();
                    }),
                DateTimePicker::make('starts_at')
                    ->label(__('Дата и время'))
                    ->timezone(fn (): string => self::viewerTimezone())
                    ->helperText(fn (): string => __('Часовой пояс CRM: ').self::viewerTimezoneLabel().'.')
                    ->live(onBlur: true)
                    ->seconds(false)
                    ->afterStateUpdated(function (Set $set): void {
                        $set('confirm_backdated', false);
                    })
                    ->required()
                    ->validationMessages(['required' => __('Укажите дату и время записи.')]),
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
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('party_size', $state === VisitFormat::HomeVisit->value ? 1 : null);

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
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
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
                    ->visible(fn (Get $get): bool => $get('visit_format') === VisitFormat::HomeVisit->value),
                TextInput::make('party_size')
                    ->label(__('Количество участников выезда'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(20)
                    ->nullable()
                    ->required(fn (Get $get): bool => $get('visit_format') === VisitFormat::HomeVisit->value)
                    ->validationMessages(['required' => __('Укажите количество участников выезда.')])
                    ->helperText(__('Сколько человек будет на выезде. Для обычного приёма поле не нужно.'))
                    ->visible(fn (Get $get): bool => $get('visit_format') === VisitFormat::HomeVisit->value),
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
            ]);
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
