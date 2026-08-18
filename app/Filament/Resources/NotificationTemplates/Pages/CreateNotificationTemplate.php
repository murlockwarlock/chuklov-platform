<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Models\User;
use App\Modules\Scenarios\Application\CreateNotificationTemplate as CreateNotificationTemplateAction;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateNotificationTemplate extends CreateRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected static ?string $title = 'Создать шаблон сообщения';

    protected function mutateFormDataBeforeCreate(array $data): array
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

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateNotificationTemplateAction::class)->handle($actor, $data);
    }
}
