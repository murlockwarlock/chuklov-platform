<?php

namespace App\Filament\Resources\SurveyDefinitions\Pages;

use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use App\Filament\Support\SurveyDefinitionFormMapper;
use App\Models\User;
use App\Modules\Surveys\Application\PublishSurveyVersion;
use App\Modules\Surveys\Application\UpdateSurveyDefinitionDraft;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditSurveyDefinition extends EditRecord
{
    protected static string $resource = SurveyDefinitionResource::class;

    protected static ?string $title = 'Редактировать тест';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        abort_unless($record instanceof SurveyDefinition, 404);
        $version = $record->versions()->latest('version')->firstOrFail();

        return [...$data, ...SurveyDefinitionFormMapper::denormalize($version)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof SurveyDefinition, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $updated = app(UpdateSurveyDefinitionDraft::class)->handle($actor, $record, SurveyDefinitionFormMapper::normalize($data));
        $this->data['start_new_metric_scale'] = false;

        return $updated;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startNewMetricScale')
                ->label('Начать новую шкалу')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Начать новую шкалу?')
                ->modalDescription('Повторные результаты после публикации будут сравниваться по новой шкале. Исторические результаты останутся без изменений.')
                ->modalSubmitActionLabel('Начать новую шкалу')
                ->action(function (): void {
                    $this->data['start_new_metric_scale'] = true;
                    $this->form->fill($this->data);
                    Notification::make()->title('Новая шкала будет создана при сохранении')->info()->send();
                }),
            Action::make('publish')->label('Опубликовать черновик')->icon('heroicon-o-check-circle')->requiresConfirmation()->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $record = $this->getRecord();
                abort_unless($record instanceof SurveyDefinition, 404);
                $draft = $record->versions()->where('status', SurveyVersionStatus::Draft)->latest('version')->first();
                abort_unless($draft instanceof SurveyVersion, 422, 'Сначала сохраните новый черновик.');
                app(PublishSurveyVersion::class)->handle($actor, $draft);
                Notification::make()->title('Версия опубликована')->success()->send();
                $this->redirect(SurveyDefinitionResource::getUrl('edit', ['record' => $record]));
            }),
        ];
    }
}
