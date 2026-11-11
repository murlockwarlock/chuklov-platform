<?php

namespace App\Filament\Resources\SurveyDefinitions\Pages;

use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

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

    public function getTabs(): array
    {
        return [
            'available' => Tab::make(__('Доступные'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_available', true)),
            'hidden' => Tab::make(__('Скрытые'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_available', false)),
            'all' => Tab::make(__('Все')),
        ];
    }
}
