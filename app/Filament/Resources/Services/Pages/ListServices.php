<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

class ListServices extends LocalizedListRecords
{
    protected static string $resource = ServiceResource::class;

    protected static ?string $title = 'Каталог услуг';

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
