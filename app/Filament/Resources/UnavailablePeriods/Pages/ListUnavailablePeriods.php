<?php

namespace App\Filament\Resources\UnavailablePeriods\Pages;

use App\Filament\Resources\UnavailablePeriods\UnavailablePeriodResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

class ListUnavailablePeriods extends LocalizedListRecords
{
    protected static string $resource = UnavailablePeriodResource::class;

    protected static ?string $title = 'Недоступное время';

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
