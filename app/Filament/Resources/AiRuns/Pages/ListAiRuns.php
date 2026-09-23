<?php

namespace App\Filament\Resources\AiRuns\Pages;

use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Support\LocalizedListRecords;

class ListAiRuns extends LocalizedListRecords
{
    protected static string $resource = AiRunResource::class;

    protected static ?string $title = 'История запусков';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
