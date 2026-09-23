<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

class ListSpecialists extends LocalizedListRecords
{
    protected static string $resource = SpecialistResource::class;

    protected static ?string $title = 'Специалисты';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
