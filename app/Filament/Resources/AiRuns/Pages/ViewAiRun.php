<?php

namespace App\Filament\Resources\AiRuns\Pages;

use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Resources\AiRuns\Schemas\AiRunInfolist;
use App\Filament\Support\CrmLabel;
use App\Filament\Support\LocalizedViewRecord;
use App\Models\User;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewReasonCode;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewAiRun extends LocalizedViewRecord
{
    protected static string $resource = AiRunResource::class;

    public function infolist(Schema $schema): Schema
    {
        return AiRunInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('download_json')
                    ->label(__('JSON с данными'))
                    ->url(fn (AiRun $record): string => $this->exportUrl($record, 'json', 'identified'))
                    ->openUrlInNewTab(),
                Action::make('download_txt')
                    ->label(__('TXT с данными'))
                    ->url(fn (AiRun $record): string => $this->exportUrl($record, 'txt', 'identified'))
                    ->openUrlInNewTab(),
                Action::make('download_anonymized_json')
                    ->label(__('JSON анонимизированный'))
                    ->url(fn (AiRun $record): string => $this->exportUrl($record, 'json', 'anonymized'))
                    ->openUrlInNewTab(),
                Action::make('download_anonymized_txt')
                    ->label(__('TXT анонимизированный'))
                    ->url(fn (AiRun $record): string => $this->exportUrl($record, 'txt', 'anonymized'))
                    ->openUrlInNewTab(),
            ])
                ->label(__('Скачать'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canViewTrace()),
            Action::make('accept_review')
                ->label(__('Ответ AI корректный'))
                ->color('success')
                ->visible(fn (AiRun $record) => $record->human_review_status === HumanReviewStatus::PendingReview)
                ->requiresConfirmation()
                ->modalHeading(__('Подтвердить результат AI'))
                ->modalDescription(__('Это оценка результата AI специалистом. Она не отправляет сообщение клиенту.'))
                ->action(function (AiRun $record, ReviewAiRun $reviewAction) {
                    $user = Auth::user();
                    if ($user) {
                        $reviewAction->handle(
                            actor: $user,
                            runId: $record->id,
                            decision: HumanReviewDecision::Accepted,
                            safeReasonCode: 'specialist_confirmed',
                        );
                        Notification::make()->title(__('Результат AI отмечен как корректный'))->success()->send();
                        $this->refreshReviewState($record);
                    }
                }),

            Action::make('reject_review')
                ->label(__('Ответ AI неверный'))
                ->color('danger')
                ->visible(fn (AiRun $record) => $record->human_review_status === HumanReviewStatus::PendingReview)
                ->form([
                    Select::make('reason_code')
                        ->label(__('Код причины'))
                        ->options(collect(HumanReviewReasonCode::cases())->mapWithKeys(fn (HumanReviewReasonCode $code): array => [$code->value => CrmLabel::enum($code)]))
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('Заметки специалиста (будут зашифрованы)'))
                        ->rows(3),
                ])
                ->action(function (AiRun $record, array $data, ReviewAiRun $reviewAction) {
                    $user = Auth::user();
                    if ($user) {
                        $reviewAction->handle(
                            actor: $user,
                            runId: $record->id,
                            decision: HumanReviewDecision::Rejected,
                            safeReasonCode: (string) ($data['reason_code'] ?? 'specialist_rejected'),
                            notes: isset($data['notes']) ? (string) $data['notes'] : null,
                        );
                        Notification::make()->title(__('Результат AI отмечен как неверный'))->danger()->send();
                        $this->refreshReviewState($record);
                    }
                }),

            Action::make('edit_and_accept_review')
                ->label(__('Исправить ответ'))
                ->color('info')
                ->visible(fn (AiRun $record) => $record->human_review_status === HumanReviewStatus::PendingReview)
                ->modalDescription(__('Исправление сохраняется только как результат проверки. Чтобы отправить текст клиенту, используйте чат.'))
                ->form([
                    Textarea::make('edited_output')
                        ->label(__('Скорректированный текст (будет зашифрован)'))
                        ->rows(6)
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('Заметки к исправлениям'))
                        ->rows(2),
                ])
                ->action(function (AiRun $record, array $data, ReviewAiRun $reviewAction) {
                    $user = Auth::user();
                    if ($user) {
                        $reviewAction->handle(
                            actor: $user,
                            runId: $record->id,
                            decision: HumanReviewDecision::EditedAndAccepted,
                            safeReasonCode: 'specialist_edited',
                            notes: isset($data['notes']) ? (string) $data['notes'] : null,
                            editedOutput: (string) ($data['edited_output'] ?? ''),
                        );
                        Notification::make()->title(__('Исправленный ответ сохранён'))->success()->send();
                        $this->refreshReviewState($record);
                    }
                }),
        ];
    }

    private function canViewTrace(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }

        return app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ViewAiTrace,
        );
    }

    private function refreshReviewState(AiRun $record): void
    {
        $record->refresh();
        $this->refreshFormData(['human_review_status']);
    }

    private function exportUrl(AiRun $record, string $format, string $identity): string
    {
        return route('admin.ai-runs.export', [
            'runId' => $record->getKey(),
            'format' => $format,
            'identity' => $identity,
        ]);
    }
}
