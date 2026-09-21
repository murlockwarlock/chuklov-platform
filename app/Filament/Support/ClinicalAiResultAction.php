<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Modules\AI\Application\Actions\GetClinicalAiResult;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewReasonCode;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use LogicException;

final class ClinicalAiResultAction
{
    public static function make(string $name, User $actor, Client $client, Closure $resolveRun): Action
    {
        $canViewTrace = self::canViewTrace($actor);
        $canReview = self::canReview($actor, $client);

        return Action::make($name)
            ->label(fn (Model $record): string => ClinicalAiPresentation::resultActionLabel(
                $resolveRun($record)?->workflow_key,
            ))
            ->icon('heroicon-o-eye')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Закрыть'))
            ->extraModalFooterActions(fn (): array => $canReview
                ? self::reviewActions($actor, $resolveRun)
                : [])
            ->fillForm(function (Model $record) use ($actor, $client, $resolveRun, $canViewTrace): array {
                $run = $resolveRun($record);
                abort_unless($run instanceof AiRun, 404);
                $result = app(GetClinicalAiResult::class)->handle($actor, $run->id, $client->id);
                $form = [
                    'result' => ClinicalAiPresentation::result(
                        $run->capability,
                        $result->outputPayload,
                        $result->outputText,
                        $run->workflow_key,
                    ),
                    'review' => ClinicalAiPresentation::review($run->human_review_status),
                    'lifecycle' => ClinicalAiPresentation::reviewGuidance($run->human_review_status, $run->workflow_key),
                    'sources' => self::sourceText($run, $result->attachmentProvenance),
                ];

                if ($canViewTrace) {
                    $form['technical'] = self::technicalText($run);
                }

                return $form;
            })
            ->schema(self::schema($canViewTrace));
    }

    private static function canViewTrace(User $actor): bool
    {
        try {
            $organization = app(OrganizationContext::class)->organization();

            return app(OrganizationAuthorizer::class)->allows(
                $actor,
                $organization,
                OrganizationPermission::ViewAiTrace,
            );
        } catch (LogicException) {
            return false;
        }
    }

    private static function canReview(User $actor, Client $client): bool
    {
        try {
            $organization = app(OrganizationContext::class)->organization();
            $authorizer = app(OrganizationAuthorizer::class);

            return (int) $client->organization_id === (int) $organization->getKey()
                && $authorizer->allows($actor, $organization, OrganizationPermission::ViewClients)
                && $authorizer->allows($actor, $organization, OrganizationPermission::ViewAiRuns)
                && $authorizer->allows($actor, $organization, OrganizationPermission::ReviewAiProposals);
        } catch (LogicException) {
            return false;
        }
    }

    private static function reviewActions(User $actor, Closure $resolveRun): array
    {
        return [
            Action::make('confirmClinicalAiResult')
                ->label(__('Подтвердить результат'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(__('Подтвердить результат'))
                ->modalDescription(fn (Model $record): string => __('Результат будет отмечен как проверенный специалистом и сможет использоваться при формировании :target.', ['target' => self::reviewTarget($resolveRun($record))]))
                ->modalSubmitActionLabel(__('Подтвердить результат'))
                ->modalCancelActionLabel(__('Отмена'))
                ->cancelParentActions()
                ->visible(fn (Model $record): bool => self::reviewableRun($record, $resolveRun) instanceof AiRun)
                ->action(function (Model $record, Component $livewire) use ($actor, $resolveRun): void {
                    $run = self::reviewableRun($record, $resolveRun);
                    abort_unless($run instanceof AiRun, 404);

                    app(ReviewAiRun::class)->handle(
                        actor: $actor,
                        runId: (int) $run->getKey(),
                        decision: HumanReviewDecision::Accepted,
                        safeReasonCode: HumanReviewReasonCode::SpecialistConfirmed->value,
                    );

                    self::refreshLivewireTable($livewire);
                    Notification::make()
                        ->title(__('Результат подтверждён специалистом.'))
                        ->success()
                        ->send();
                }),
            Action::make('rejectClinicalAiResult')
                ->label(__('Отклонить результат'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->modalHeading(__('Отклонить результат'))
                ->modalDescription(fn (Model $record): string => __('Результат останется в истории, но не будет использоваться как подтверждённый источник для :target.', ['target' => self::reviewTarget($resolveRun($record))]))
                ->modalSubmitActionLabel(__('Отклонить результат'))
                ->modalCancelActionLabel(__('Отмена'))
                ->cancelParentActions()
                ->schema([
                    Select::make('reason_code')
                        ->label(__('Причина'))
                        ->options(collect(HumanReviewReasonCode::cases())->mapWithKeys(
                            fn (HumanReviewReasonCode $code): array => [$code->value => CrmLabel::enum($code)],
                        ))
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('Заметка специалиста'))
                        ->rows(3),
                ])
                ->visible(fn (Model $record): bool => self::reviewableRun($record, $resolveRun) instanceof AiRun)
                ->action(function (Model $record, array $data, Component $livewire) use ($actor, $resolveRun): void {
                    $run = self::reviewableRun($record, $resolveRun);
                    abort_unless($run instanceof AiRun, 404);

                    app(ReviewAiRun::class)->handle(
                        actor: $actor,
                        runId: (int) $run->getKey(),
                        decision: HumanReviewDecision::Rejected,
                        safeReasonCode: (string) $data['reason_code'],
                        notes: filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                    );

                    self::refreshLivewireTable($livewire);
                    Notification::make()
                        ->title(__('Результат отклонён специалистом.'))
                        ->danger()
                        ->send();
                }),
        ];
    }

    private static function reviewableRun(Model $record, Closure $resolveRun): ?AiRun
    {
        $run = $resolveRun($record);

        return $run instanceof AiRun
            && $run->status === AiRunStatus::Succeeded
            && $run->human_review_status === HumanReviewStatus::PendingReview
            ? $run
            : null;
    }

    private static function reviewTarget(?AiRun $run): string
    {
        return $run?->workflow_key === ClinicalSynthesizerWorkflow::CourseReport->value
            ? __('итогового отчёта курса')
            : __('клинического резюме');
    }

    private static function refreshLivewireTable(Component $livewire): void
    {
        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }

    private static function schema(bool $canViewTrace): array
    {
        $schema = [
            Textarea::make('result')
                ->label(__('Результат анализа'))
                ->rows(14)
                ->disabled()
                ->dehydrated(false),
            Placeholder::make('review')
                ->label(__('Проверка специалиста'))
                ->badge()
                ->color(fn (mixed $state): string => ClinicalAiPresentation::reviewColor((string) $state))
                ->dehydrated(false),
            Textarea::make('lifecycle')
                ->label(__('Состояние результата'))
                ->rows(5)
                ->disabled()
                ->dehydrated(false),
            Textarea::make('sources')
                ->label(__('Источники'))
                ->rows(4)
                ->disabled()
                ->dehydrated(false),
        ];

        if ($canViewTrace) {
            $schema[] = Section::make(__('Техническая информация (аудит)'))
                ->schema([
                    Textarea::make('technical')
                        ->label(__('Версии и время запуска'))
                        ->rows(4)
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->collapsed();
        }

        return $schema;
    }

    private static function sourceText(AiRun $record, array $provenance): string
    {
        if ($record->capability === AiCapability::ClinicalSynthesizer) {
            $references = collect((array) $record->input_references)
                ->filter(static fn (mixed $reference): bool => is_array($reference));
            $hasSessions = $references->contains(fn (array $reference): bool => ($reference['type'] ?? null) === 'medical_session');
            $hasSurveys = $references->contains(fn (array $reference): bool => ($reference['type'] ?? null) === 'survey_attempt');
            $hasUpstream = $references->contains(fn (array $reference): bool => ($reference['type'] ?? null) === 'ai_run');

            $title = $record->workflow_key === ClinicalSynthesizerWorkflow::CourseReport->value
                ? __('Источники итогового отчёта курса')
                : __('Источники клинического резюме');

            return implode("\n", [
                $title.':',
                __('Профиль клиента: доступен.'),
                __('Проверенные результаты предыдущих анализов: :state.', ['state' => $hasUpstream ? __('доступны') : __('отсутствуют')]),
                __('История сеансов: :state.', ['state' => $hasSessions ? __('доступна') : __('отсутствует')]),
                __('Совместимые опросы: :state.', ['state' => $hasSurveys ? __('доступны') : __('отсутствуют')]),
                __('Источник 9 систем/MSQ и его оценивание: отсутствует в авторитетных материалах.'),
            ]);
        }

        if ($provenance === []) {
            return __('Профиль клиента и выбранные источники анализа.');
        }

        $sources = collect($provenance)
            ->map(static function (array $source): string {
                $role = match ($source['role'] ?? null) {
                    'front' => __('Спереди'),
                    'side' => __('Сбоку'),
                    'back' => __('Сзади'),
                    default => null,
                };
                $type = match ($source['attachment_type'] ?? null) {
                    AttachmentType::MedicalReport->value => __('Медицинский документ'),
                    AttachmentType::PosturePhoto->value => __('Фото осанки'),
                    default => __('Защищённый файл'),
                };
                $format = match (strtolower((string) ($source['mime_type'] ?? ''))) {
                    'application/pdf' => 'PDF',
                    'image/jpeg', 'image/png', 'image/webp' => __('Изображение'),
                    default => null,
                };

                return trim(implode(' · ', array_filter([$role, $type, $format])));
            })
            ->filter()
            ->implode("\n");

        return $sources !== '' ? $sources : __('Профиль клиента и выбранные источники анализа.');
    }

    private static function technicalText(AiRun $record): string
    {
        return implode("\n", [
            __('Запуск: #:id', ['id' => $record->getKey()]),
            __('Версия промпта: :id', ['id' => $record->prompt_version_id ?? '—']),
            __('Релиз модели: :id', ['id' => $record->model_release_id ?? '—']),
            __('Сгенерирован: :date', ['date' => $record->finished_at?->format('d.m.Y H:i:s') ?? '—']),
            __('Проверка: :status', ['status' => ClinicalAiPresentation::review($record->human_review_status)]),
        ]);
    }
}
