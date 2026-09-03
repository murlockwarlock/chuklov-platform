<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Models\User;
use App\Modules\Scenarios\Application\UpdateNotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class EditNotificationTemplate extends EditRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected static ?string $title = 'Редактировать шаблон сообщения';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        abort_unless($this->record instanceof NotificationTemplate, 404);
        $latest = $this->record->versions()->latest('version')->firstOrFail();

        return [
            ...$data,
            'subject' => $latest->subject,
            'body' => $latest->body,
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        try {
            $data['variables'] = ScenarioTemplateVariableCatalog::used(
                (string) ($data['body'] ?? ''),
                (string) ($data['subject'] ?? ''),
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'body' => 'Текст сообщения содержит неподдерживаемые переменные. Используйте список доступных данных.',
            ]);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof NotificationTemplate, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateNotificationTemplate::class)->handle($actor, $record, [
            ...$data,
            'template_key' => $record->template_key,
            'locale' => $record->locale,
        ]);
    }

    protected function getRedirectUrl(): ?string
    {
        return NotificationTemplateResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Шаблон сохранён';
    }
}
