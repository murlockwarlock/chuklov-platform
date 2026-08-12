<?php

namespace App\Filament\Resources\ScheduleExceptions\Pages;

use App\Filament\Resources\ScheduleExceptions\ScheduleExceptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScheduleExceptions extends ListRecords
{
    protected static string $resource = ScheduleExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
