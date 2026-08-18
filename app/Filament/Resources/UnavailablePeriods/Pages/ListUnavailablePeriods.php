<?php

namespace App\Filament\Resources\UnavailablePeriods\Pages;

use App\Filament\Resources\UnavailablePeriods\UnavailablePeriodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUnavailablePeriods extends ListRecords
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
