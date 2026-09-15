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
            ->modalCancelActionLabel('Закрыть')
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
                ->label('Подтвердить результат')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Подтвердить результат')
                ->modalDescription(fn (Model $record): string => 'Результат будет отмечен как проверенный специалистом и сможет использоваться при формировании '.self::reviewTarget($resolveRun($record)).'.')
                ->modalSubmitActionLabel('Подтвердить результат')
                ->modalCancelActionLabel('Отмена')
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
                        ->title('Результат подтверждён специалистом.')
                        ->success()
                        ->send();
                }),
            Action::make('rejectClinicalAiResult')
                ->label('Отклонить результат')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->modalHeading('Отклонить результат')
                ->modalDescription(fn (Model $record): string => 'Результат останется в истории, но не будет использоваться как подтверждённый источник для '.self::reviewTarget($resolveRun($record)).'.')
                ->modalSubmitActionLabel('Отклонить результат')
                ->modalCancelActionLabel('Отмена')
                ->cancelParentActions()
                ->schema([
                    Select::make('reason_code')
                        ->label('Причина')
                        ->options(collect(HumanReviewReasonCode::cases())->mapWithKeys(
                            fn (HumanReviewReasonCode $code): array => [$code->value => $code->label()],
                        ))
                        ->required(),
                    Textarea::make('notes')
                        ->label('Заметка специалиста')
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
                        ->title('Результат отклонён специалистом.')
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
            ? 'итогового отчёта курса'
            : 'клинического резюме';
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
                ->label('Результат анализа')
                ->rows(14)
                ->disabled()
                ->dehydrated(false),
            Placeholder::make('review')
                ->label('Проверка специалиста')
                ->badge()
                ->color(fn (mixed $state): string => ClinicalAiPresentation::reviewColor((string) $state))
                ->dehydrated(false),
            Textarea::make('lifecycle')
                ->label('Состояние результата')
                ->rows(5)
                ->disabled()
                ->dehydrated(false),
            Textarea::make('sources')
                ->label('Источники')
                ->rows(4)
                ->disabled()
                ->dehydrated(false),
        ];

        if ($canViewTrace) {
            $schema[] = Section::make('Техническая информация (аудит)')
                ->schema([
                    Textarea::make('technical')
                        ->label('Версии и время запуска')
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
                ? 'Источники итогового отчёта курса'
                : 'Источники клинического резюме';

            return implode("\n", [
                $title.':',
                'Профиль клиента: доступен.',
                'Проверенные результаты предыдущих анализов: '.($hasUpstream ? 'доступны.' : 'отсутствуют.'),
                'История сеансов: '.($hasSessions ? 'доступна.' : 'отсутствует.'),
                'Совместимые опросы: '.($hasSurveys ? 'доступны.' : 'отсутствуют.'),
                'Источник 9 систем/MSQ и его оценивание: отсутствует в авторитетных материалах.',
            ]);
        }

        if ($provenance === []) {
            return 'Профиль клиента и выбранные источники анализа.';
        }

        $sources = collect($provenance)
            ->map(static function (array $source): string {
                $role = match ($source['role'] ?? null) {
                    'front' => 'Спереди',
                    'side' => 'Сбоку',
                    'back' => 'Сзади',
                    default => null,
                };
                $type = match ($source['attachment_type'] ?? null) {
                    AttachmentType::MedicalReport->value => 'Медицинский документ',
                    AttachmentType::PosturePhoto->value => 'Фото осанки',
                    default => 'Защищённый файл',
                };
                $format = match (strtolower((string) ($source['mime_type'] ?? ''))) {
                    'application/pdf' => 'PDF',
                    'image/jpeg', 'image/png', 'image/webp' => 'Изображение',
                    default => null,
                };

                return trim(implode(' · ', array_filter([$role, $type, $format])));
            })
            ->filter()
            ->implode("\n");

        return $sources !== '' ? $sources : 'Профиль клиента и выбранные источники анализа.';
    }

    private static function technicalText(AiRun $record): string
    {
        return implode("\n", [
            'Запуск: #'.$record->getKey(),
            'Версия промпта: '.($record->prompt_version_id ?? '—'),
            'Релиз модели: '.($record->model_release_id ?? '—'),
            'Сгенерирован: '.($record->finished_at?->format('d.m.Y H:i:s') ?? '—'),
            'Проверка: '.ClinicalAiPresentation::review($record->human_review_status),
        ]);
    }
}
