<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Models\User;
use App\Modules\Scenarios\Application\CreateNotificationTemplate as CreateNotificationTemplateAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateNotificationTemplate extends CreateRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateNotificationTemplateAction::class)->handle($actor, $data);
    }
}
