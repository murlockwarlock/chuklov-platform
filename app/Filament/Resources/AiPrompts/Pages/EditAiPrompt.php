<?php

namespace App\Filament\Resources\AiPrompts\Pages;

use App\Filament\Resources\AiEvaluations\AiEvaluationResource;
use App\Filament\Resources\AiPrompts\AiPromptResource;
use App\Filament\Resources\AiPrompts\Schemas\PromptVersionForm;
use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Support\AiPlaygroundResultPresentation;
use App\Models\User;
use App\Modules\AI\Application\Actions\ActivatePromptVersion;
use App\Modules\AI\Application\Actions\CreatePromptDraft;
use App\Modules\AI\Application\Actions\ExecutePlaygroundRun;
use App\Modules\AI\Application\Actions\ExportPromptBundle;
use App\Modules\AI\Application\Actions\SavePromptDraft;
use App\Modules\AI\Application\Actions\UpdateAiPrompt;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EditAiPrompt extends EditRecord
{
    protected static string $resource = AiPromptResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->prompt();

        return $record->name.' — '.$this->capabilityLabel($record);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.ai-prompts.active-workspace'),
            Section::make('Настройки промпта')
                ->description('Название, описание и технические параметры карточки.')
                ->collapsed()
                ->schema([$this->getFormContentComponent()]),
            Section::make('История версий')
                ->description('Черновики, активная версия и ранее использованные версии.')
                ->collapsed()
                ->schema([$this->getRelationManagersContentComponent()]),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof AiPrompt, 404);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateAiPrompt::class)->handle($actor, $record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function editPromptAction(): Action
    {
        $draft = $this->draftVersion();
        $source = $draft ?? $this->activeVersion();

        return Action::make('editPrompt')
            ->label($draft instanceof AiPromptVersion ? 'Продолжить черновик' : 'Изменить промпт')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading($draft instanceof AiPromptVersion ? "Черновик v{$draft->version}" : 'Новая версия промпта')
            ->modalDescription('Изменения сохраняются в черновике. Текущая активная версия останется неизменной до явной активации.')
            ->fillForm(fn (): array => $source instanceof AiPromptVersion ? PromptVersionForm::data($source) : [])
            ->schema(PromptVersionForm::components($source))
            ->modalSubmitActionLabel('Сохранить черновик')
            ->action(function (array $data, CreatePromptDraft $create, SavePromptDraft $save): void {
                $actor = Auth::user();
                abort_unless($actor instanceof User, 403);
                $draft = $this->draftVersion();
                $version = $draft instanceof AiPromptVersion
                    ? $save->handle($actor, $draft->getKey(), $data)
                    : $create->handle($actor, $this->prompt()->getKey(), $data);

                Notification::make()->title("Черновик v{$version->version} сохранён")->success()->send();
                $this->refreshPrompt();
            })
            ->slideOver()
            ->stickyModalHeader()
            ->stickyModalFooter();
    }

    public function playgroundAction(): Action
    {
        return Action::make('playground')
            ->label('Проверить')
            ->icon(Heroicon::OutlinedPlay)
            ->color('info')
            ->schema([
                Textarea::make('test_input')->label('Пример запроса')->rows(5)->default('{"query": "Тестовый запрос"}')->required(),
                Select::make('model_release_id')
                    ->label('Модель для staging-проверки')
                    ->options(fn (): array => AiPromptResource::modelReleaseOptions($this->prompt()))
                    ->searchable()
                    ->native(false)
                    ->required(),
            ])
            ->action(function (array $data, ExecutePlaygroundRun $playground): void {
                $actor = Auth::user();
                abort_unless($actor instanceof User, 403);

                try {
                    $rawInput = trim((string) $data['test_input']);
                    $decoded = str_starts_with($rawInput, '{') ? json_decode($rawInput, true) : null;
                    $result = $playground->handle(
                        actor: $actor,
                        capability: $this->prompt()->capability,
                        promptVersionId: $this->prompt()->active_version_id,
                        modelReleaseId: (int) $data['model_release_id'],
                        inputVariables: is_array($decoded) ? $decoded : ['query' => $rawInput],
                    );
                    $this->notifyPlaygroundResult($result);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Ошибка выполнения в песочнице')
                        ->body($exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Проверьте настройки и повторите попытку.')
                        ->danger()
                        ->send();
                }
            });
    }

    public function evaluationsAction(): Action
    {
        return Action::make('evaluations')
            ->label('Запустить тесты')
            ->icon(Heroicon::OutlinedBeaker)
            ->url(fn (): string => AiEvaluationResource::getUrl('index', [
                'tableFilters' => ['capability' => ['value' => $this->prompt()->capability->value]],
            ]));
    }

    public function exportAction(): Action
    {
        return Action::make('export')
            ->label('Экспорт')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => $this->prompt()->active_version_id !== null)
            ->action(function (ExportPromptBundle $export): StreamedResponse {
                $actor = Auth::user();
                abort_unless($actor instanceof User, 403);
                $bundle = $export->handle($actor, (int) $this->prompt()->active_version_id);
                $json = json_encode($bundle->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

                return response()->streamDownload(
                    static function () use ($json): void {
                        echo $json;
                    },
                    $this->prompt()->key.'-active.json',
                    ['Content-Type' => 'application/json; charset=UTF-8'],
                );
            });
    }

    public function activateDraftAction(): Action
    {
        return Action::make('activateDraft')
            ->label('Сделать черновик активным')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (): bool => $this->draftVersion() instanceof AiPromptVersion)
            ->requiresConfirmation()
            ->modalHeading('Сделать черновик активной версией?')
            ->modalDescription('Текущая активная версия перейдёт в историю. Её текст останется доступен и неизменным.')
            ->action(function (ActivatePromptVersion $activate): void {
                $actor = Auth::user();
                $draft = $this->draftVersion();
                abort_unless($actor instanceof User && $draft instanceof AiPromptVersion, 403);
                $activate->handle($actor, $draft->getKey());
                $this->refreshPrompt();
                Notification::make()->title("Версия v{$draft->version} активирована")->success()->send();
            });
    }

    public function prompt(): AiPrompt
    {
        $record = $this->getRecord();
        abort_unless($record instanceof AiPrompt, 404);

        return $record;
    }

    public function capabilityLabel(?AiPrompt $prompt = null): string
    {
        $prompt ??= $this->prompt();

        return $prompt->capability->value === 'client_companion'
            ? 'AI-компаньон'
            : $prompt->capability->label();
    }

    public function activeVersion(): ?AiPromptVersion
    {
        return $this->prompt()->activeVersion;
    }

    public function hasDraft(): bool
    {
        return $this->draftVersion() instanceof AiPromptVersion;
    }

    private function draftVersion(): ?AiPromptVersion
    {
        return $this->prompt()->versions()
            ->where('status', PromptVersionStatus::Draft->value)
            ->latest('version')
            ->first();
    }

    private function refreshPrompt(): void
    {
        $this->record = $this->prompt()->fresh(['activeVersion']);
    }

    private function notifyPlaygroundResult(AiRunResult $result): void
    {
        if ($result->isSuccess()) {
            $notification = Notification::make()
                ->title('Проверка успешна')
                ->body(AiPlaygroundResultPresentation::body($result))
                ->success();
            if ($result->runId > 0) {
                $notification->actions([
                    Action::make('technicalData')
                        ->label('Технические данные')
                        ->url(AiRunResource::getUrl('view', ['record' => $result->runId]))
                        ->button()
                        ->openUrlInNewTab(),
                ]);
            }
            $notification->send();

            return;
        }

        Notification::make()->title('Ошибка выполнения в песочнице')->body($result->errorMessageSanitized ?? 'Провайдер не вернул проверяемый ответ.')->danger()->send();
    }
}
