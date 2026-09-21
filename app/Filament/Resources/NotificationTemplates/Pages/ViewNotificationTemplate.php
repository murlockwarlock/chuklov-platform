<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Support\LocalizedViewRecord;
use Filament\Actions\EditAction;

final class ViewNotificationTemplate extends LocalizedViewRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected static ?string $title = 'Шаблон сообщения';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label(__('Редактировать шаблон'))
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
