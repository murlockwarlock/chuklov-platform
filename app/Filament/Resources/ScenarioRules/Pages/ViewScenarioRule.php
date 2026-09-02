<?php

namespace App\Filament\Resources\ScenarioRules\Pages;

use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewScenarioRule extends ViewRecord
{
    protected static string $resource = ScenarioRuleResource::class;

    protected static ?string $title = 'Авто-сообщение';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Редактировать авто-сообщение')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
