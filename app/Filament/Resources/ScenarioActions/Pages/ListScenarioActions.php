<?php

namespace App\Filament\Resources\ScenarioActions\Pages;

use App\Filament\Resources\ScenarioActions\ScenarioActionResource;
use App\Filament\Support\LocalizedListRecords;

final class ListScenarioActions extends LocalizedListRecords
{
    protected static string $resource = ScenarioActionResource::class;

    protected static ?string $title = 'История сообщений';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
