<?php

namespace App\Filament\Resources\UnavailablePeriods\Pages;

use App\Filament\Resources\ScheduleExceptions\ScheduleChangeTypeActions;
use App\Filament\Resources\UnavailablePeriods\UnavailablePeriodResource;
use App\Filament\Support\LocalizedListRecords;

class ListUnavailablePeriods extends LocalizedListRecords
{
    protected static string $resource = UnavailablePeriodResource::class;

    protected static ?string $title = 'Изменения расписания';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            ScheduleChangeTypeActions::make(true),
        ];
    }
}
