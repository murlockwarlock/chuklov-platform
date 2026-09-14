<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Support\ClinicalAiPresentation;
use App\Filament\Support\ClinicalAiResultAction;
use App\Models\User;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Application\Actions\StartClinicalDocumentAnalysis;
use App\Modules\AI\Application\Actions\StartClinicalSynthesis;
use App\Modules\AI\Application\Actions\StartPostureAnalysis;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewReasonCode;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Surveys\Application\SurveyAuthorization;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class ClientClinicalAiRelationManager extends RelationManager
{
    protected static string $relationship = 'aiRuns';

    protected static ?string $title = 'Клинический AI';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $ownerRecord instanceof Client) {
            return false;
        }

        $organization = app(OrganizationContext::class)->organization();
        $authorizer = app(OrganizationAuthorizer::class);

        return $authorizer->allows($actor, $organization, OrganizationPermission::ViewClients)
            && $authorizer->allows($actor, $organization, OrganizationPermission::ViewAiRuns)
            && (int) $ownerRecord->organization_id === (int) $organization->getKey();
    }

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $client = $this->getOwnerRecord();

        abort_unless($actor instanceof User, 403);
        abort_unless($client instanceof Client, 404);
        $organization = app(OrganizationContext::class)->organization();
        $canReviewAiProposals = app(OrganizationAuthorizer::class)->allows(
            $actor,
            $organization,
            OrganizationPermission::ReviewAiProposals,
        );

        return $table
            ->heading('Клинический AI')
            ->poll(fn (): ?string => $this->shouldPoll() ? '5s' : null)
            ->stackedOnMobile()
            ->modifyQueryUsing(function (Builder $query) use ($client): Builder {
                $table = (new AiRun)->getTable();

                return $query
                    ->where("{$table}.organization_id", app(OrganizationContext::class)->id())
                    ->where("{$table}.client_id", $client->getKey())
                    ->whereIn("{$table}.capability", [
                        AiCapability::ClinicalDocumentExtraction,
                        AiCapability::PostureAnalysis,
                        AiCapability::ClinicalSynthesizer,
                    ]);
            })
            ->columns([
                TextColumn::make('capability')
                    ->label('Анализ')
                    ->formatStateUsing(fn (AiCapability|string $state): string => ClinicalAiPresentation::capability($state))
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Состояние')
                    ->badge()
                    ->wrap()
                    ->color(fn (AiRunStatus|string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (AiRunStatus|string $state): string => ClinicalAiPresentation::status($state)),
                TextColumn::make('human_review_status')
                    ->label('Проверка специалиста')
                    ->badge()
                    ->color(fn (HumanReviewStatus|string $state): string => self::reviewColor($state))
                    ->formatStateUsing(fn (HumanReviewStatus|string $state): string => ClinicalAiPresentation::review($state))
                    ->wrap(),
                TextColumn::make('error_category')
                    ->label('Причина ошибки')
                    ->state(fn (AiRun $record): ?string => $record->error_category?->label())
                    ->placeholder('—')
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('created_at')
                    ->label('Запущен')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Завершён')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->headerActions([
                Action::make('postureAnalysis')
                    ->label('Анализ осанки')
                    ->icon('heroicon-o-camera')
                    ->schema([
                        Placeholder::make('posture_instructions')
                            ->label('Три обязательных фото')
                            ->content('Выберите одно фото спереди, одно сбоку и одно сзади. Анализ начнётся только после заполнения всех трёх полей.'),
                        Select::make('front')
                            ->label('Спереди')
                            ->options(fn (): array => self::postureOptions($client))
                            ->searchable()
                            ->required(),
                        Select::make('side')
                            ->label('Сбоку')
                            ->options(fn (): array => self::postureOptions($client))
                            ->searchable()
                            ->required(),
                        Select::make('back')
                            ->label('Сзади')
                            ->options(fn (): array => self::postureOptions($client))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) use ($actor, $client): void {
                        try {
                            app(StartPostureAnalysis::class)->handle(
                                actor: $actor,
                                client: $client,
                                attachmentIds: [
                                    'front' => (int) $data['front'],
                                    'side' => (int) $data['side'],
                                    'back' => (int) $data['back'],
                                ],
                            );
                            self::success('Анализ осанки запущен.');
                        } catch (Throwable $exception) {
                            self::failure($exception);
                        }
                    }),
                Action::make('clinicalSynthesis')
                    ->label('Клиническое резюме')
                    ->icon('heroicon-o-document-text')
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => self::sourceAvailability($actor, $client))
                    ->action(function () use ($actor, $client): void {
                        try {
                            app(StartClinicalSynthesis::class)->handle($actor, $client);
                            self::success('Клиническое резюме создаётся.');
                        } catch (Throwable $exception) {
                            self::failure($exception);
                        }
                    }),
            ])
            ->recordActions([
                ClinicalAiResultAction::make(
                    name: 'openResult',
                    actor: $actor,
                    client: $client,
                    resolveRun: static fn (Model $record): ?AiRun => $record instanceof AiRun ? $record : null,
                )
                    ->visible(fn (AiRun $record): bool => $record->status === AiRunStatus::Succeeded),
                ActionGroup::make([
                    Action::make('acceptReview')
                        ->label('Проверено')
                        ->color('success')
                        ->visible(fn (AiRun $record): bool => $canReviewAiProposals
                            && $record->status === AiRunStatus::Succeeded
                            && $record->human_review_status === HumanReviewStatus::PendingReview)
                        ->requiresConfirmation()
                        ->action(function (AiRun $record) use ($actor): void {
                            app(ReviewAiRun::class)->handle(
                                actor: $actor,
                                runId: $record->id,
                                decision: HumanReviewDecision::Accepted,
                                safeReasonCode: HumanReviewReasonCode::SpecialistConfirmed->value,
                            );
                            $this->resetTable();
                            self::success('Результат подтверждён специалистом.');
                        }),
                    Action::make('rejectReview')
                        ->label('Отклонить')
                        ->color('danger')
                        ->visible(fn (AiRun $record): bool => $canReviewAiProposals
                            && $record->status === AiRunStatus::Succeeded
                            && $record->human_review_status === HumanReviewStatus::PendingReview)
                        ->schema([
                            Select::make('reason_code')
                                ->label('Причина')
                                ->options(collect(HumanReviewReasonCode::cases())->mapWithKeys(fn (HumanReviewReasonCode $code): array => [$code->value => $code->label()]))
                                ->required(),
                            Textarea::make('notes')->label('Заметка специалиста')->rows(3),
                        ])
                        ->action(function (AiRun $record, array $data) use ($actor): void {
                            app(ReviewAiRun::class)->handle(
                                actor: $actor,
                                runId: $record->id,
                                decision: HumanReviewDecision::Rejected,
                                safeReasonCode: (string) $data['reason_code'],
                                notes: isset($data['notes']) ? (string) $data['notes'] : null,
                            );
                            $this->resetTable();
                            self::failureNotification('Результат отклонён специалистом.');
                        }),
                    Action::make('rerun')
                        ->label('Повторить анализ')
                        ->icon('heroicon-o-arrow-path')
                        ->visible(fn (AiRun $record): bool => $record->status->isTerminal())
                        ->requiresConfirmation()
                        ->action(function (AiRun $record) use ($actor, $client): void {
                            try {
                                self::rerun($record, $actor, $client);
                                self::success('Новый запуск создан. Предыдущий результат сохранён в истории.');
                            } catch (Throwable $exception) {
                                self::failure($exception);
                            }
                        }),
                ])
                    ->label('Действия')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->button()
                    ->color('gray')
                    ->size('sm'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25])
            ->emptyStateHeading('Анализы ещё не запускались')
            ->emptyStateDescription('Запустите анализ документа, выберите три фото осанки или подготовьте клиническое резюме.');
    }

    private function shouldPoll(): bool
    {
        $client = $this->getOwnerRecord();
        if (! $client instanceof Client) {
            return false;
        }

        return AiRun::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('client_id', $client->getKey())
            ->whereIn('capability', [
                AiCapability::ClinicalDocumentExtraction,
                AiCapability::PostureAnalysis,
                AiCapability::ClinicalSynthesizer,
            ])
            ->whereIn('status', [AiRunStatus::Preparing, AiRunStatus::Queued, AiRunStatus::Running])
            ->exists();
    }

    /** @return array<int, string> */
    private static function postureOptions(Client $client): array
    {
        return MedicalAttachment::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->where('attachment_type', AttachmentType::PosturePhoto)
            ->whereNull('evaluation_fixture_key')
            ->orderByDesc('created_at')
            ->get(['id', 'original_filename'])
            ->mapWithKeys(static fn (MedicalAttachment $attachment): array => [
                (int) $attachment->getKey() => (string) $attachment->original_filename,
            ])
            ->all();
    }

    private static function sourceAvailability(User $actor, Client $client): string
    {
        $lines = [];
        foreach ([
            AiCapability::ClinicalDocumentExtraction->value => 'Анализ медицинского документа',
            AiCapability::PostureAnalysis->value => 'Анализ осанки',
        ] as $capability => $label) {
            $available = AiRun::query()
                ->where('organization_id', $client->organization_id)
                ->where('client_id', $client->getKey())
                ->where('capability', $capability)
                ->where('status', AiRunStatus::Succeeded)
                ->whereIn('human_review_status', [HumanReviewStatus::Accepted, HumanReviewStatus::EditedAndAccepted])
                ->exists();
            $lines[] = $label.': '.($available ? 'проверен специалистом' : 'пока отсутствует или ожидает проверки');
        }

        $surveyAvailable = app(SurveyAuthorization::class)->allowsView($actor, $client);
        $lines[] = 'Результаты совместимых опросов: '.($surveyAvailable ? 'будут включены при наличии' : 'недоступны для текущего специалиста');
        $lines[] = 'Источник 9 систем/MSQ и его оценивание: отсутствует в авторитетных материалах.';

        return implode("\n", $lines);
    }

    private static function rerun(AiRun $record, User $actor, Client $client): void
    {
        $references = collect((array) $record->input_references);
        if ($record->capability === AiCapability::ClinicalDocumentExtraction) {
            $reference = $references->firstWhere('type', 'medical_attachment');
            if (! is_array($reference) || ! isset($reference['id'])) {
                throw new \InvalidArgumentException('Исходный медицинский документ недоступен для повторного анализа.');
            }

            $attachmentId = (int) $reference['id'];
            $attachment = MedicalAttachment::query()
                ->where('organization_id', $client->organization_id)
                ->where('client_id', $client->getKey())
                ->whereKey($attachmentId)
                ->firstOrFail();
            app(StartClinicalDocumentAnalysis::class)->handle($actor, $attachment, true);

            return;
        }

        if ($record->capability === AiCapability::PostureAnalysis) {
            $ids = $references
                ->filter(fn (array $reference): bool => ($reference['type'] ?? null) === 'medical_attachment')
                ->mapWithKeys(fn (array $reference): array => [(string) ($reference['role'] ?? '') => (int) $reference['id']])
                ->all();
            app(StartPostureAnalysis::class)->handle($actor, $client, $ids, true);

            return;
        }

        app(StartClinicalSynthesis::class)->handle($actor, $client, true);
    }

    private static function statusColor(AiRunStatus|string $status): string
    {
        $status = $status instanceof AiRunStatus ? $status->value : (string) $status;

        return match ($status) {
            'succeeded' => 'success',
            'running' => 'info',
            'queued', 'preparing' => 'gray',
            'invalid_output' => 'warning',
            'failed', 'timed_out', 'cancelled' => 'danger',
            default => 'gray',
        };
    }

    private static function reviewColor(HumanReviewStatus|string $status): string
    {
        $status = $status instanceof HumanReviewStatus ? $status->value : (string) $status;

        return match ($status) {
            'accepted', 'edited_and_accepted' => 'success',
            'pending_review' => 'warning',
            'rejected' => 'danger',
            default => 'gray',
        };
    }

    private static function success(string $message): void
    {
        Notification::make()->title($message)->success()->send();
    }

    private static function failure(Throwable $exception): void
    {
        Notification::make()
            ->title(ClinicalAiPresentation::failure(null, $exception))
            ->danger()
            ->send();
    }

    private static function failureNotification(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
    }
}
