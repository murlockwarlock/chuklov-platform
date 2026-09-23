<?php

namespace App\Filament\Resources\LocationDays\Pages;

use App\Filament\Resources\LocationDays\LocationDayResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

class ListLocationDays extends LocalizedListRecords
{
    protected static string $resource = LocationDayResource::class;

    protected static ?string $title = 'Дни выезда';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Добавить день выезда'))];
    }
}
