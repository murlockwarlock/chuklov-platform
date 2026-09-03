<?php

namespace App\Filament\Resources\LocationDays\Pages;

use App\Filament\Resources\LocationDays\LocationDayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocationDays extends ListRecords
{
    protected static string $resource = LocationDayResource::class;

    protected static ?string $title = 'Дни выезда';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить день выезда')];
    }
}
