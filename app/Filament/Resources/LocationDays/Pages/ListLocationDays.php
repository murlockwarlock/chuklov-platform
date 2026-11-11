<?php

namespace App\Filament\Resources\LocationDays\Pages;

use App\Filament\Resources\LocationDays\LocationDayResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLocationDays extends LocalizedListRecords
{
    protected static string $resource = LocationDayResource::class;

    protected static ?string $title = 'Дни выезда специалистов';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Добавить день выезда'))];
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make(__('Активные'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true)),
            'inactive' => Tab::make(__('Отключённые'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),
            'all' => Tab::make(__('Все')),
        ];
    }
}
