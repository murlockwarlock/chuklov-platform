<?php

namespace App\Filament\Resources\ScenarioRules\Pages;

use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListScenarioRules extends ListRecords
{
    protected static string $resource = ScenarioRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
