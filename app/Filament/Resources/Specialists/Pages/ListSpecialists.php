<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSpecialists extends ListRecords
{
    protected static string $resource = SpecialistResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
