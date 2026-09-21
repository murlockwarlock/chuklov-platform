<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Support\ClinicalAiPresentation;
use App\Filament\Support\ClinicalAiResultAction;
use App\Filament\Support\CrmLabel;
use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Application\Actions\StartClinicalDocumentAnalysis;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewReasonCode;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Application\ListClientAttachments;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Throwable;

final class ClientAttachmentsRelationManager extends LocalizedRelationManager
{
    private ?array $latestDocumentAnalysisRuns = null;

    protected static string $relationship = 'medicalAttachments';

    protected static ?string $title = 'Файлы и МРТ';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $ownerRecord instanceof Client
            && app(AttachmentAuthorization::class)->allowsView($actor, $ownerRecord);
    }

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $client = $this->getOwnerRecord();

        abort_unless($actor instanceof User, 403);
        abort_unless($client instanceof Client, 404);
        $organization = app(OrganizationContext::class)->organization();
        $canViewAiRuns = app(OrganizationAuthorizer::class)->allows(
            $actor,
            $organization,
            OrganizationPermission::ViewAiRuns,
        );
        $canReviewAiProposals = app(OrganizationAuthorizer::class)->allows(
            $actor,
            $organization,
            OrganizationPermission::ReviewAiProposals,
        );

        return $table
            ->heading(__('Файлы и МРТ'))
            ->poll(fn (): ?string => $canViewAiRuns && $this->shouldPoll() ? '5s' : null)
            ->stackedOnMobile()
            ->modifyQueryUsing(
                fn (Builder $query): Builder => app(ListClientAttachments::class)->query($actor, $client),
            )
            ->columns([
                TextColumn::make('original_filename')
                    ->label(__('Файл'))
                    ->limit(36)
                    ->wrap(),
                TextColumn::make('attachment_type')
                    ->label(__('Тип'))
                    ->badge()
                    ->formatStateUsing(fn (AttachmentType|string $state): string => $state instanceof AttachmentType
                        ? CrmLabel::enum($state)
                        : (CrmLabel::enum(AttachmentType::tryFrom($state)) ?? __('Файл'))),
                TextColumn::make('size_bytes')
                    ->label(__('Размер'))
                    ->formatStateUsing(fn (int|string $state): string => self::formatBytes((int) $state))
                    ->visibleFrom('sm'),
                TextColumn::make('created_at')
                    ->label(__('Загружен'))
                    ->dateTime('d.m.Y H:i')
                    ->visibleFrom('md'),
                TextColumn::make('analysis_status')
                    ->label(__('Статус анализа'))
                    ->state(fn (MedicalAttachment $record): string => $this->analysisStatus($record))
                    ->badge()
                    ->color(fn (MedicalAttachment $record): string => $this->analysisStatusColor($record))
                    ->visible($canViewAiRuns),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label(__('Загрузить файл'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->schema([
                        Select::make('attachment_type')
                            ->label(__('Тип файла'))
                            ->options([
                                AttachmentType::MedicalReport->value => CrmLabel::enum(AttachmentType::MedicalReport),
                                AttachmentType::PosturePhoto->value => CrmLabel::enum(AttachmentType::PosturePhoto),
                            ])
                            ->required(),
                        FileUpload::make('file')
                            ->label(__('Файл'))
                            ->acceptedFileTypes([
                                'application/pdf',
                                'text/plain',
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->maxSize((int) ceil((int) config('medical.attachment_max_bytes', 20_971_520) / 1024))
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (array $data) use ($actor, $client): void {
                        abort_unless($data['file'] instanceof UploadedFile, 422);

                        $attachment = app(UploadMedicalAttachment::class)->handle(
                            actor: $actor,
                            command: new AttachmentUploadCommand(
                                file: $data['file'],
                                attachmentType: AttachmentType::from((string) $data['attachment_type']),
                                clientId: (int) $client->getKey(),
                            ),
                        );

                        self::sendUploadNotification();
                    })
                    ->visible(fn (): bool => app(AttachmentAuthorization::class)->allowsUpload($actor, $client)),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('Просмотр'))
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Закрыть'))
                    ->modalWidth('7xl')
                    ->modalContent(fn (MedicalAttachment $record): View => view('filament.resources.clients.attachment-preview', [
                        'url' => app(GetTemporaryAttachmentUrl::class)->handlePreview($actor, $record),
                        'filename' => $record->original_filename,
                        'mimeType' => strtolower($record->mime_type),
                    ]))
                    ->visible(fn (MedicalAttachment $record): bool => GetTemporaryAttachmentUrl::supportsPreview($record->mime_type)),
                ClinicalAiResultAction::make(
                    name: 'openDocumentAnalysisResult',
                    actor: $actor,
                    client: $client,
                    resolveRun: fn (Model $record): ?AiRun => $record instanceof MedicalAttachment
                        ? $this->latestDocumentAnalysisRun($record)
                        : null,
                )
                    ->visible(fn (MedicalAttachment $record): bool => $canViewAiRuns && $this->canOpenDocumentAnalysisResult($record)),
                ActionGroup::make([
                    Action::make('download')
                        ->label(__('Скачать'))
                        ->action(function (MedicalAttachment $record) use ($actor): mixed {
                            return redirect()->to(app(GetTemporaryAttachmentUrl::class)->handle($actor, $record));
                        }),
                    Action::make('startDocumentAnalysis')
                        ->label(__('Запустить анализ'))
                        ->icon('heroicon-o-sparkles')
                        ->requiresConfirmation()
                        ->visible(fn (MedicalAttachment $record): bool => $canViewAiRuns && $this->canStartDocumentAnalysis($record))
                        ->action(function (MedicalAttachment $record) use ($actor): void {
                            try {
                                app(StartClinicalDocumentAnalysis::class)->handle($actor, $record);
                                $this->latestDocumentAnalysisRuns = null;
                                Notification::make()
                                    ->title(__('Анализ документа запущен'))
                                    ->success()
                                    ->send();
                            } catch (Throwable $exception) {
                                Notification::make()
                                    ->title(ClinicalAiPresentation::failure(null, $exception))
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Action::make('retryDocumentAnalysis')
                        ->label(__('Повторить анализ'))
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->visible(fn (MedicalAttachment $record): bool => $canViewAiRuns && $this->canRetryDocumentAnalysis($record))
                        ->action(function (MedicalAttachment $record) use ($actor): void {
                            try {
                                app(StartClinicalDocumentAnalysis::class)->handle($actor, $record, true);
                                $this->latestDocumentAnalysisRuns = null;
                                Notification::make()
                                    ->title(__('Повторный анализ поставлен в очередь'))
                                    ->success()
                                    ->send();
                            } catch (Throwable $exception) {
                                Notification::make()
                                    ->title(ClinicalAiPresentation::failure(null, $exception))
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Action::make('acceptDocumentAnalysisReview')
                        ->label(__('Проверено'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (MedicalAttachment $record): bool => $canViewAiRuns
                            && $canReviewAiProposals
                            && $this->canReviewDocumentAnalysis($record))
                        ->requiresConfirmation()
                        ->action(function (MedicalAttachment $record) use ($actor): void {
                            $run = $this->reviewableDocumentAnalysisRun($record);
                            app(ReviewAiRun::class)->handle(
                                actor: $actor,
                                runId: $run->getKey(),
                                decision: HumanReviewDecision::Accepted,
                                safeReasonCode: HumanReviewReasonCode::SpecialistConfirmed->value,
                            );
                            $this->latestDocumentAnalysisRuns = null;
                            $this->resetTable();
                            Notification::make()
                                ->title(__('Результат подтверждён специалистом.'))
                                ->success()
                                ->send();
                        }),
                    Action::make('rejectDocumentAnalysisReview')
                        ->label(__('Отклонить'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (MedicalAttachment $record): bool => $canViewAiRuns
                            && $canReviewAiProposals
                            && $this->canReviewDocumentAnalysis($record))
                        ->schema([
                            Select::make('reason_code')
                                ->label(__('Причина'))
                                ->options(collect(HumanReviewReasonCode::cases())->mapWithKeys(fn (HumanReviewReasonCode $code): array => [$code->value => CrmLabel::enum($code)]))
                                ->required(),
                            Textarea::make('notes')
                                ->label(__('Заметка специалиста'))
                                ->rows(3),
                        ])
                        ->action(function (MedicalAttachment $record, array $data) use ($actor): void {
                            $run = $this->reviewableDocumentAnalysisRun($record);
                            app(ReviewAiRun::class)->handle(
                                actor: $actor,
                                runId: $run->getKey(),
                                decision: HumanReviewDecision::Rejected,
                                safeReasonCode: (string) $data['reason_code'],
                                notes: isset($data['notes']) ? (string) $data['notes'] : null,
                            );
                            $this->latestDocumentAnalysisRuns = null;
                            $this->resetTable();
                            Notification::make()
                                ->title(__('Результат отклонён специалистом.'))
                                ->danger()
                                ->send();
                        }),
                ])
                    ->label(__('Действия'))
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->button()
                    ->color('gray')
                    ->size('sm'),
            ])
            ->paginated([10, 25])
            ->emptyStateHeading(__('Файлов пока нет'))
            ->emptyStateDescription(__('Загрузите медицинское заключение или фото осанки.'));
    }

    private function shouldPoll(): bool
    {
        $this->latestDocumentAnalysisRuns = null;

        $client = $this->getOwnerRecord();
        if (! $client instanceof Client) {
            return false;
        }

        return AiRun::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('client_id', $client->getKey())
            ->where('capability', AiCapability::ClinicalDocumentExtraction)
            ->whereIn('status', [AiRunStatus::Preparing, AiRunStatus::Queued, AiRunStatus::Running])
            ->exists();
    }

    private function analysisStatus(MedicalAttachment $attachment): string
    {
        if ($attachment->attachment_type !== AttachmentType::MedicalReport) {
            return '—';
        }

        return ClinicalAiPresentation::documentStatus($this->latestDocumentAnalysisRun($attachment)?->status);
    }

    private function analysisStatusColor(MedicalAttachment $attachment): string
    {
        if ($attachment->attachment_type !== AttachmentType::MedicalReport) {
            return 'gray';
        }

        return ClinicalAiPresentation::documentStatusColor($this->latestDocumentAnalysisRun($attachment)?->status);
    }

    private function canStartDocumentAnalysis(MedicalAttachment $attachment): bool
    {
        return $attachment->attachment_type === AttachmentType::MedicalReport
            && $attachment->evaluation_fixture_key === null
            && $this->latestDocumentAnalysisRun($attachment) === null;
    }

    private function canRetryDocumentAnalysis(MedicalAttachment $attachment): bool
    {
        return $attachment->attachment_type === AttachmentType::MedicalReport
            && $attachment->evaluation_fixture_key === null
            && $this->latestDocumentAnalysisRun($attachment)?->status?->isTerminal() === true;
    }

    private function canOpenDocumentAnalysisResult(MedicalAttachment $attachment): bool
    {
        return $attachment->attachment_type === AttachmentType::MedicalReport
            && $this->latestDocumentAnalysisRun($attachment)?->status === AiRunStatus::Succeeded;
    }

    private function canReviewDocumentAnalysis(MedicalAttachment $attachment): bool
    {
        $run = $this->latestDocumentAnalysisRun($attachment);

        return $attachment->attachment_type === AttachmentType::MedicalReport
            && $attachment->evaluation_fixture_key === null
            && $run instanceof AiRun
            && $run->status === AiRunStatus::Succeeded
            && $run->human_review_status === HumanReviewStatus::PendingReview;
    }

    private function reviewableDocumentAnalysisRun(MedicalAttachment $attachment): AiRun
    {
        $run = $this->latestDocumentAnalysisRun($attachment);

        abort_unless($this->canReviewDocumentAnalysis($attachment) && $run instanceof AiRun, 404);

        return $run;
    }

    private function latestDocumentAnalysisRun(MedicalAttachment $attachment): ?AiRun
    {
        return $this->latestDocumentAnalysisRuns()[(int) $attachment->getKey()] ?? null;
    }

    /** @return array<int, AiRun> */
    private function latestDocumentAnalysisRuns(): array
    {
        if ($this->latestDocumentAnalysisRuns !== null) {
            return $this->latestDocumentAnalysisRuns;
        }

        $client = $this->getOwnerRecord();
        if (! $client instanceof Client) {
            return $this->latestDocumentAnalysisRuns = [];
        }

        $organizationId = app(OrganizationContext::class)->id();
        $attachmentIds = MedicalAttachment::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->flip()
            ->all();

        if ($attachmentIds === []) {
            return $this->latestDocumentAnalysisRuns = [];
        }

        $runs = AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('capability', AiCapability::ClinicalDocumentExtraction)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'organization_id',
                'client_id',
                'capability',
                'status',
                'input_references',
                'context_provenance',
                'prompt_version_id',
                'model_release_id',
                'human_review_status',
                'finished_at',
                'created_at',
            ]);
        $latest = [];

        foreach ($runs as $run) {
            foreach ((array) $run->input_references as $reference) {
                if (! is_array($reference) || ($reference['type'] ?? null) !== 'medical_attachment') {
                    continue;
                }

                $attachmentId = (int) ($reference['id'] ?? 0);
                if ($attachmentId < 1 || ! isset($attachmentIds[$attachmentId]) || isset($latest[$attachmentId])) {
                    continue;
                }

                $latest[$attachmentId] = $run;
            }
        }

        return $this->latestDocumentAnalysisRuns = $latest;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' '.__('Б');
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' '.__('КБ');
        }

        return round($bytes / (1024 * 1024), 1).' '.__('МБ');
    }

    private static function sendUploadNotification(): void
    {
        Notification::make()
            ->title(__('Файл загружен'))
            ->success()
            ->send();
    }
}
