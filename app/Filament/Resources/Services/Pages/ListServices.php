<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListServices extends LocalizedListRecords
{
    protected static string $resource = ServiceResource::class;

    protected static ?string $title = 'Каталог услуг';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
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
