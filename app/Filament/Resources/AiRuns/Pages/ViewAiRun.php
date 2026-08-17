<?php

namespace App\Filament\Resources\AiRuns\Pages;

use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Resources\AiRuns\Schemas\AiRunInfolist;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewReasonCode;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewAiRun extends ViewRecord
{
    protected static string $resource = AiRunResource::class;

    public function infolist(Schema $schema): Schema
    {
        return AiRunInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('accept_review')
                ->label('Принять предложение')
                ->color('success')
                ->visible(fn (AiRun $record) => $record->human_review_status === HumanReviewStatus::PendingReview)
                ->requiresConfirmation()
                ->modalHeading('Подтверждение принятия предложения AI')
                ->modalDescription('Вы подтверждаете, что проверили сгенерированный результат.')
                ->action(function (AiRun $record, ReviewAiRun $reviewAction) {
                    $user = Auth::user();
                    if ($user) {
                        $reviewAction->handle(
                            actor: $user,
                            runId: $record->id,
                            decision: HumanReviewDecision::Accepted,
                            safeReasonCode: 'specialist_confirmed',
                        );
                        Notification::make()->title('Предложение принято')->success()->send();
                        $this->refreshFormData(['human_review_status']);
                    }
                }),

            Action::make('reject_review')
                ->label('Отклонить')
                ->color('danger')
                ->visible(fn (AiRun $record) => $record->human_review_status === HumanReviewStatus::PendingReview)
                ->form([
                    Select::make('reason_code')
                        ->label('Код причины')
                        ->options(collect(HumanReviewReasonCode::cases())->mapWithKeys(fn (HumanReviewReasonCode $code): array => [$code->value => $code->label()]))
                        ->required(),
                    Textarea::make('notes')
                        ->label('Заметки специалиста (будут зашифрованы)')
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
                        Notification::make()->title('Предложение отклонено')->danger()->send();
                        $this->refreshFormData(['human_review_status']);
                    }
                }),

            Action::make('edit_and_accept_review')
                ->label('Отредактировать и принять')
                ->color('info')
                ->visible(fn (AiRun $record) => $record->human_review_status === HumanReviewStatus::PendingReview)
                ->form([
                    Textarea::make('edited_output')
                        ->label('Скорректированный текст (будет зашифрован)')
                        ->rows(6)
                        ->required(),
                    Textarea::make('notes')
                        ->label('Заметки к исправлениям')
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
                        Notification::make()->title('Отредактировано и принято')->success()->send();
                        $this->refreshFormData(['human_review_status']);
                    }
                }),
        ];
    }
}
