<?php

namespace App\Filament\Resources\SpecialistServiceAssignments\Pages;

use App\Filament\Resources\SpecialistServiceAssignments\SpecialistServiceAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSpecialistServiceAssignments extends ListRecords
{
    protected static string $resource = SpecialistServiceAssignmentResource::class;

    protected static ?string $title = 'Специалисты и услуги';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
