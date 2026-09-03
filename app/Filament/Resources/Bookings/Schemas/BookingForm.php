<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Models\User;
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

class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('client_id')
                    ->label('Клиент')
                    ->options(fn (): array => Client::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->orderBy('full_name')
                        ->pluck('full_name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('service_id')
                    ->label('Услуга')
                    ->options(fn (): array => Service::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->where('is_active', true)
                        ->where('catalog_type', CatalogItemType::Service->value)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->live(),
                Select::make('specialist_id')
                    ->label('Специалист')
                    ->options(fn (Get $get): array => Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->where('is_active', true)
                        ->whereIn('id', SpecialistServiceAssignment::query()
                            ->where('organization_id', app(OrganizationContext::class)->id())
                            ->where('service_id', (int) $get('service_id'))
                            ->select('specialist_id'))
                        ->orderBy('display_name')
                        ->pluck('display_name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                DateTimePicker::make('starts_at')
                    ->label('Дата и время')
                    ->timezone(fn (): string => self::viewerTimezone())
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
                    ->label('Количество участников')
                    ->integer()
                    ->default(1)
                    ->minValue(1)
                    ->maxValue(20)
                    ->required(),
                TextInput::make('location')
                    ->label(fn (Get $get): string => $get('visit_format') === VisitFormat::Office->value ? 'Адрес приёма' : 'Адрес выезда')
                    ->default(fn (Get $get): ?string => $get('visit_format') === VisitFormat::Office->value
                        ? app(OrganizationContext::class)->organization()->settings()->where('setting_key', 'office_location')->value('string_value')
                        : null)
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
}
