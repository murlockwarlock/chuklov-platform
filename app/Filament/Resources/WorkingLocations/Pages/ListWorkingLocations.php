<?php

namespace App\Filament\Resources\WorkingLocations\Pages;

use App\Filament\Resources\WorkingLocations\WorkingLocationResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWorkingLocations extends LocalizedListRecords
{
    protected static string $resource = WorkingLocationResource::class;

    protected static ?string $title = 'Локации';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Добавить локацию'))];
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make(__('Активные'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true)),
            'inactive' => Tab::make(__('Скрытые'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),
            'all' => Tab::make(__('Все')),
        ];
    }
}
