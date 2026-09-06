<?php

namespace App\Filament\Resources\AiRuns\Pages;

use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Resources\AiRuns\Schemas\AiRunInfolist;
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
            ActionGroup::make([
                Action::make('download_json')
                    ->label('JSON с данными')
                    ->url(fn (AiRun $record): string => $this->exportUrl($record, 'json', 'identified'))
                    ->openUrlInNewTab(),
                Action::make('download_txt')
                    ->label('TXT с данными')
                    ->url(fn (AiRun $record): string => $this->exportUrl($record, 'txt', 'identified'))
                    ->openUrlInNewTab(),
                Action::make('download_anonymized_json')
                    ->label('JSON анонимизированный')
                    ->url(fn (AiRun $record): string => $this->exportUrl($record, 'json', 'anonymized'))
                    ->openUrlInNewTab(),
                Action::make('download_anonymized_txt')
                    ->label('TXT анонимизированный')
                    ->url(fn (AiRun $record): string => $this->exportUrl($record, 'txt', 'anonymized'))
                    ->openUrlInNewTab(),
            ])
                ->label('Скачать')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canViewTrace()),
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

    private function exportUrl(AiRun $record, string $format, string $identity): string
    {
        return route('admin.ai-runs.export', [
            'runId' => $record->getKey(),
            'format' => $format,
            'identity' => $identity,
        ]);
    }
}
