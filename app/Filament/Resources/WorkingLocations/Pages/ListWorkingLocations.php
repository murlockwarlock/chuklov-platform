<?php

namespace App\Filament\Resources\WorkingLocations\Pages;

use App\Filament\Resources\WorkingLocations\WorkingLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkingLocations extends ListRecords
{
    protected static string $resource = WorkingLocationResource::class;

    protected static ?string $title = 'Локации';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить локацию')];
    }
}
