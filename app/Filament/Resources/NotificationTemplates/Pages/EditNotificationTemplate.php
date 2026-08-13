<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Models\User;
use App\Modules\Scenarios\Application\UpdateNotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditNotificationTemplate extends EditRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        abort_unless($this->record instanceof NotificationTemplate, 404);
        $latest = $this->record->versions()->latest('version')->firstOrFail();

        return [
            ...$data,
            'subject' => $latest->subject,
            'body' => $latest->body,
            'variables' => $latest->variables,
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof NotificationTemplate, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateNotificationTemplate::class)->handle($actor, $record, $data);
    }
}
