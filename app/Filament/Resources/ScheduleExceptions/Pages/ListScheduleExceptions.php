<?php

namespace App\Filament\Resources\ScheduleExceptions\Pages;

use App\Filament\Resources\ScheduleExceptions\ScheduleExceptionResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

class ListScheduleExceptions extends LocalizedListRecords
{
    protected static string $resource = ScheduleExceptionResource::class;

    protected static ?string $title = 'Изменения расписания';

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
