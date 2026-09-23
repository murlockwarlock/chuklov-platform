<?php

namespace App\Filament\Resources\SurveyDefinitions\Pages;

use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use App\Filament\Support\LocalizedEditRecord;
use App\Filament\Support\SurveyDefinitionFormMapper;
use App\Models\User;
use App\Modules\Surveys\Application\PublishSurveyVersion;
use App\Modules\Surveys\Application\SurveyDefinitionSnapshotHasher;
use App\Modules\Surveys\Application\UpdateSurveyDefinitionDraft;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

final class EditSurveyDefinition extends LocalizedEditRecord
{
    protected static string $resource = SurveyDefinitionResource::class;

    protected static ?string $title = 'Редактировать тест';

    public string $surveyBuilderTab = '0';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        abort_unless($record instanceof SurveyDefinition, 404);
        $version = $record->versions()->latest('version')->firstOrFail();
        $publishedVersion = $record->activeVersion()->first();

        return [
            ...$data,
            ...SurveyDefinitionFormMapper::denormalize($version),
            'expected_snapshot' => app(SurveyDefinitionSnapshotHasher::class)->forDefinition($record, $version),
            'published_version_number' => $publishedVersion?->version,
            'draft_version_number' => $version->status === SurveyVersionStatus::Draft ? $version->version : null,
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof SurveyDefinition, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $updated = app(UpdateSurveyDefinitionDraft::class)->handle(
            $actor,
            $record,
            SurveyDefinitionFormMapper::normalize($data),
            is_array($data['legacy_scoring'] ?? null),
        );
        $this->data['start_new_metric_scale'] = false;

        return $updated;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveDraft')
                ->label(__('Сохранить черновик'))
                ->icon('heroicon-o-document-check')
                ->action('save'),
            Action::make('startNewMetricScale')
                ->label(__('Начать новую шкалу'))
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading(__('Начать новую шкалу?'))
                ->modalDescription(__('Повторные результаты после публикации будут сравниваться по новой шкале. Исторические результаты останутся без изменений.'))
                ->modalSubmitActionLabel(__('Начать новую шкалу'))
                ->action(function (): void {
                    $this->data['start_new_metric_scale'] = true;
                    $this->form->fill($this->data);
                    Notification::make()->title(__('Новая шкала будет создана при сохранении'))->info()->send();
                }),
            Action::make('publish')->label(__('Опубликовать черновик'))->icon('heroicon-o-check-circle')->requiresConfirmation()->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $record = $this->getRecord();
                abort_unless($record instanceof SurveyDefinition, 404);
                $draft = $record->versions()->where('status', SurveyVersionStatus::Draft)->latest('version')->first();
                abort_unless($draft instanceof SurveyVersion, 422, __('Сначала сохраните новый черновик.'));
                app(PublishSurveyVersion::class)->handle($actor, $draft);
                Notification::make()->title(__('Версия опубликована'))->success()->send();
                $this->redirect(SurveyDefinitionResource::getUrl('edit', ['record' => $record]));
            }),
        ];
    }
}
