<?php

namespace App\Filament\Resources\ContentSections\Pages;

use App\Filament\Resources\ContentSections\ContentSectionResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListContentSections extends LocalizedListRecords
{
    protected static string $resource = ContentSectionResource::class;

    protected static ?string $title = 'Разделы контента';

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
            'active' => Tab::make(__('Опубликованные'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_visible', true)),
            'hidden' => Tab::make(__('Скрытые'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_visible', false)),
            'all' => Tab::make(__('Все')),
        ];
    }
}
