<?php

namespace App\Filament\Resources\ScenarioRules\Pages;

use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

final class ListScenarioRules extends LocalizedListRecords
{
    protected static string $resource = ScenarioRuleResource::class;

    protected static ?string $title = 'Авто-сообщения';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
