<?php

namespace App\Filament\Resources\SpecialistServiceAssignments\Schemas;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class SpecialistServiceAssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('specialist_id')
                    ->label('Specialist')
                    ->options(fn (): array => Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->orderBy('display_name')
                        ->pluck('display_name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('service_id')
                    ->label('Service')
                    ->options(fn (): array => Service::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ]);
    }
}
