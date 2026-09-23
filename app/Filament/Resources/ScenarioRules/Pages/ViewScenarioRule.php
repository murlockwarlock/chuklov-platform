<?php

namespace App\Filament\Resources\ScenarioRules\Pages;

use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Filament\Support\LocalizedViewRecord;
use Filament\Actions\EditAction;

final class ViewScenarioRule extends LocalizedViewRecord
{
    protected static string $resource = ScenarioRuleResource::class;

    protected static ?string $title = 'Авто-сообщение';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label(__('Редактировать авто-сообщение'))
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
