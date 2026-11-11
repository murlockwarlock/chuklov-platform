<?php

namespace App\Filament\Resources\KnowledgeSources\Pages;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListKnowledgeSources extends LocalizedListRecords
{
    protected static string $resource = KnowledgeSourceResource::class;

    protected static ?string $title = 'База знаний';

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
            'active' => Tab::make(__('Активные'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'active')),
            'retired' => Tab::make(__('Скрытые'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'retired')),
            'all' => Tab::make(__('Все')),
        ];
    }
}
