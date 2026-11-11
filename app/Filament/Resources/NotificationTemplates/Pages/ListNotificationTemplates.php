<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListNotificationTemplates extends LocalizedListRecords
{
    protected static string $resource = NotificationTemplateResource::class;

    protected static ?string $title = 'Шаблоны сообщений';

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
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true)),
            'inactive' => Tab::make(__('Отключённые'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),
            'all' => Tab::make(__('Все')),
        ];
    }
}
