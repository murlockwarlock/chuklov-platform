<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('client_id')
                    ->label('Client')
                    ->options(fn (): array => Client::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->orderBy('full_name')
                        ->pluck('full_name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('service_id')
                    ->label('Service')
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
                    ->label('Specialist')
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
                    ->label('Start')
                    ->seconds(false)
                    ->required(),
                Select::make('visit_format')
                    ->label('Visit format')
                    ->options([
                        VisitFormat::Office->value => 'Office',
                        VisitFormat::HomeVisit->value => 'Home visit',
                        VisitFormat::Online->value => 'Online',
                    ])
                    ->required()
                    ->live(),
                TextInput::make('client_timezone')
                    ->label('Client display timezone')
                    ->maxLength(64),
                Select::make('meeting_link_mode')
                    ->label('Meeting-link mode')
                    ->options([
                        MeetingLinkMode::Auto->value => 'Automatic (future provider)',
                        MeetingLinkMode::Manual->value => 'Manual',
                    ])
                    ->visible(fn (Get $get): bool => $get('visit_format') === VisitFormat::Online->value)
                    ->nullable(),
                TextInput::make('party_size')
                    ->label('Party size')
                    ->integer()
                    ->default(1)
                    ->minValue(1)
                    ->maxValue(20)
                    ->required(),
                TextInput::make('location')
                    ->label('Home-visit destination')
                    ->maxLength(500)
                    ->visible(fn (Get $get): bool => $get('visit_format') === VisitFormat::HomeVisit->value),
                TextInput::make('idempotency_key')
                    ->label('Idempotency key')
                    ->helperText('Use one stable key if the request must be retried.')
                    ->maxLength(128)
                    ->required(),
            ]);
    }
}
