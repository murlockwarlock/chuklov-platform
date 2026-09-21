<?php

namespace App\Filament\Resources\SurveyDefinitions\Pages;

use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

final class ListSurveyDefinitions extends LocalizedListRecords
{
    protected static string $resource = SurveyDefinitionResource::class;

    protected static ?string $title = 'Опросники и тесты';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
