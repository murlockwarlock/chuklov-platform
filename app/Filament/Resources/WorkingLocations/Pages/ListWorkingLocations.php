<?php

namespace App\Filament\Resources\WorkingLocations\Pages;

use App\Filament\Resources\WorkingLocations\WorkingLocationResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

class ListWorkingLocations extends LocalizedListRecords
{
    protected static string $resource = WorkingLocationResource::class;

    protected static ?string $title = 'Локации';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Добавить локацию'))];
    }
}
