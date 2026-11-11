<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAiProviders extends LocalizedListRecords
{
    protected static string $resource = AiProviderResource::class;

    protected static ?string $title = 'Провайдеры и модели';

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
            'enabled' => Tab::make(__('Включённые'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_enabled', true)),
            'disabled' => Tab::make(__('Отключённые'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_enabled', false)),
            'all' => Tab::make(__('Все')),
        ];
    }
}
