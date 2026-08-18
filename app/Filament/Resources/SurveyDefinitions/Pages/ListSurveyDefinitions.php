<?php

namespace App\Filament\Resources\SurveyDefinitions\Pages;

use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSurveyDefinitions extends ListRecords
{
    protected static string $resource = SurveyDefinitionResource::class;

    protected static ?string $title = 'Опросники и тесты';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
