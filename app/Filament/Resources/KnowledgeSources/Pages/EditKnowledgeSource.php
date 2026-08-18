<?php

namespace App\Filament\Resources\KnowledgeSources\Pages;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Models\User;
use App\Modules\Knowledge\Application\ReactivateKnowledgeSource;
use App\Modules\Knowledge\Application\RetireKnowledgeSource;
use App\Modules\Knowledge\Application\UpdateKnowledgeSource as UpdateKnowledgeSourceAction;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditKnowledgeSource extends EditRecord
{
    protected static string $resource = KnowledgeSourceResource::class;

    protected static ?string $title = 'Редактировать источник знаний';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        if (! $record instanceof KnowledgeSource) {
            abort(404);
        }
        $revision = $record->revisions()->latest('version')->first();

        return [
            ...$data,
            'type' => $record->type->value,
            'content' => $revision?->content,
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof KnowledgeSource, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        app(UpdateKnowledgeSourceAction::class)->handle($actor, $record, $data);

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retire')->label('Скрыть источник')->color('danger')->requiresConfirmation()->visible(function (): bool {
                $record = $this->getRecord();

                return $record instanceof KnowledgeSource && $record->status->value === 'active';
            })->action(function (): void {
                $actor = auth()->user();
                $record = $this->getRecord();
                abort_unless($actor instanceof User && $record instanceof KnowledgeSource, 403);
                app(RetireKnowledgeSource::class)->handle($actor, $record);
                Notification::make()->title('Источник скрыт из поиска')->success()->send();
                $this->redirect(KnowledgeSourceResource::getUrl('index'));
            }),
            Action::make('reactivate')->label('Вернуть в поиск')->visible(function (): bool {
                $record = $this->getRecord();

                return $record instanceof KnowledgeSource && $record->status->value === 'retired';
            })->action(function (): void {
                $actor = auth()->user();
                $record = $this->getRecord();
                abort_unless($actor instanceof User && $record instanceof KnowledgeSource, 403);
                app(ReactivateKnowledgeSource::class)->handle($actor, $record);
                Notification::make()->title('Источник снова доступен в поиске')->success()->send();
                $this->redirect(KnowledgeSourceResource::getUrl('edit', ['record' => $record]));
            }),
        ];
    }
}
