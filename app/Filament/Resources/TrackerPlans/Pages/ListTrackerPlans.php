<?php

namespace App\Filament\Resources\TrackerPlans\Pages;

use App\Filament\Resources\TrackerPlans\TrackerPlanResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListTrackerPlans extends LocalizedListRecords
{
    protected static string $resource = TrackerPlanResource::class;

    protected static ?string $title = 'Трекер и тарифы';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Добавить тариф'))];
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make(__('Активные'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true)),
            'archived' => Tab::make(__('Архивные'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),
            'all' => Tab::make(__('Все')),
        ];
    }
}
