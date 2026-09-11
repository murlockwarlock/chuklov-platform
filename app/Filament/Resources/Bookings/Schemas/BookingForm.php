<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Identity\Application\CreateClient as CreateClientAction;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('specialist_id')
                    ->label('Специалист')
                    ->options(fn (): array => Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->where('is_active', true)
                        ->orderBy('display_name')
                        ->orderBy('id')
                        ->pluck('display_name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
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
                    ->label('Услуга')
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
                    ->live(),
                Select::make('client_id')
                    ->label('Клиент')
                    ->options(fn (): array => Client::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->orderBy('full_name')
                        ->orderBy('id')
                        ->get(['id', 'full_name'])
                        ->mapWithKeys(static fn (Client $client): array => [
                            $client->getKey() => trim((string) $client->full_name) ?: '#'.$client->getKey(),
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Нажмите +, если клиента ещё нет в базе. Telegram подключается отдельной подтверждённой ссылкой после создания.')
                    ->createOptionModalHeading('Добавить клиента')
                    ->createOptionForm([
                        TextInput::make('full_name')
                            ->label('Имя и фамилия')
                            ->required()
                            ->maxLength(160),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(320),
                        TextInput::make('phone')
                            ->label('Телефон')
                            ->tel()
                            ->maxLength(32),
                        Select::make('language')
                            ->label('Язык')
                            ->options([
                                'ru' => 'Русский',
                                'en' => 'Английский',
                            ])
                            ->default(fn (): string => (string) config('portal.default_locale', 'ru'))
                            ->required(),
                        Select::make('timezone')
                            ->label('Часовой пояс')
                            ->options(fn (Get $get): array => TimezoneOptions::options(
                                current: $get('timezone'),
                                organization: app(OrganizationContext::class)->defaultTimezone(),
                            ))
                            ->default(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                            ->searchable()
                            ->required(),
                        TextInput::make('lead_source')
                            ->label('Источник клиента')
                            ->placeholder('Например: Telegram, Instagram, рекомендация')
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
                            language: (string) ($data['language'] ?? config('portal.default_locale', 'ru')),
                            timezone: (string) ($data['timezone'] ?? app(OrganizationContext::class)->defaultTimezone()),
                            leadSource: isset($data['lead_source']) && trim((string) $data['lead_source']) !== '' ? (string) $data['lead_source'] : null,
                        );

                        return (int) $client->getKey();
                    }),
                DateTimePicker::make('starts_at')
                    ->label('Дата и время')
                    ->timezone(fn (): string => self::viewerTimezone())
                    ->helperText(fn (): string => 'Часовой пояс CRM: '.self::viewerTimezoneLabel().'.')
                    ->live(onBlur: true)
                    ->seconds(false)
                    ->required(),
                Select::make('visit_format')
                    ->label('Формат визита')
                    ->options([
                        VisitFormat::Office->value => 'В клинике',
                        VisitFormat::HomeVisit->value => 'Выезд на дом',
                        VisitFormat::Online->value => 'Онлайн',
                    ])
                    ->required()
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
                    ->label('Локация')
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
                    ->helperText('Время доступности рассчитывается по часовому поясу выбранной локации.'),
                TextInput::make('location_area')
                    ->label('Район выезда')
                    ->maxLength(160)
                    ->visible(fn (Get $get): bool => $get('visit_format') === VisitFormat::HomeVisit->value),
                TextInput::make('party_size')
                    ->label('Количество участников выезда')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(20)
                    ->nullable()
                    ->required(fn (Get $get): bool => $get('visit_format') === VisitFormat::HomeVisit->value)
                    ->helperText('Сколько человек будет на выезде. Для обычного приёма поле не нужно.')
                    ->visible(fn (Get $get): bool => $get('visit_format') === VisitFormat::HomeVisit->value),
                TextInput::make('location')
                    ->label(fn (Get $get): string => $get('visit_format') === VisitFormat::Office->value ? 'Адрес приёма' : 'Адрес выезда')
                    ->default(fn (Get $get): ?string => $get('visit_format') === VisitFormat::Office->value
                        ? app(OrganizationContext::class)->organization()->settings()->where('setting_key', 'office_location')->value('string_value')
                        : null)
                    ->required(fn (Get $get): bool => $get('visit_format') === VisitFormat::HomeVisit->value)
                    ->helperText(fn (Get $get): string => $get('visit_format') === VisitFormat::Office->value
                        ? 'Можно изменить адрес только для этой записи.'
                        : 'Укажите место выезда для этой записи.')
                    ->maxLength(500)
                    ->visible(fn (Get $get): bool => in_array($get('visit_format'), [VisitFormat::Office->value, VisitFormat::HomeVisit->value], true)),
            ]);
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
