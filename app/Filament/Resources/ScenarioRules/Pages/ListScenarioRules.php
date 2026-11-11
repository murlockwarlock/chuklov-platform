<?php

namespace App\Filament\Resources\ScenarioRules\Pages;

use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

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

    public function getTabs(): array
    {
        return [
            'active' => Tab::make(__('Включённые'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_enabled', true)),
            'inactive' => Tab::make(__('Отключённые'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_enabled', false)),
            'all' => Tab::make(__('Все')),
        ];
    }
}
