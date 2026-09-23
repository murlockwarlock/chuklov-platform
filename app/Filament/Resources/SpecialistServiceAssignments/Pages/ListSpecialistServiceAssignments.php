<?php

namespace App\Filament\Resources\SpecialistServiceAssignments\Pages;

use App\Filament\Resources\SpecialistServiceAssignments\SpecialistServiceAssignmentResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

class ListSpecialistServiceAssignments extends LocalizedListRecords
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
