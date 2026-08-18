<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewNotificationTemplate extends ViewRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected static ?string $title = 'Шаблон сообщения';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Редактировать шаблон')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
