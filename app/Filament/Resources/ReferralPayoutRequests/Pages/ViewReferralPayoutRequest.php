<?php

namespace App\Filament\Resources\ReferralPayoutRequests\Pages;

use App\Filament\Resources\ReferralPayoutRequests\ReferralPayoutRequestResource;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Referrals\Application\TransitionReferralPayoutRequest;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewReferralPayoutRequest extends ViewRecord
{
    protected static string $resource = ReferralPayoutRequestResource::class;

    protected static ?string $title = 'Запрос на выплату';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Одобрить')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (ReferralPayoutRequest $record): bool => $this->canManage()
                    && $record->status === ReferralPayoutRequestStatus::Requested)
                ->modalDescription('Заявка перейдёт в статус «Одобрена». Выплата автоматически не выполняется.')
                ->action(function (ReferralPayoutRequest $record): void {
                    $this->performTransition($record, ReferralPayoutRequestStatus::Approved, 'approve');
                }),
            Action::make('reject')
                ->label('Отклонить')
                ->color('danger')
                ->schema([
                    Textarea::make('reason')->label('Причина отклонения')->required()->maxLength(2000),
                ])
                ->visible(fn (ReferralPayoutRequest $record): bool => $this->canManage()
                    && in_array($record->status, [ReferralPayoutRequestStatus::Requested, ReferralPayoutRequestStatus::Approved], true))
                ->modalDescription('Заявка завершится как отклонённая. Причина сохранится в истории.')
                ->action(function (ReferralPayoutRequest $record, array $data): void {
                    $reason = (string) ($data['reason'] ?? '');
                    $this->performTransition($record, ReferralPayoutRequestStatus::Rejected, 'reject-'.sha1($reason), reason: $reason);
                }),
            Action::make('paid')
                ->label('Отметить как выплаченную')
                ->color('primary')
                ->schema([
                    TextInput::make('payment_reference')->label('Платёжная пометка или ссылка')->maxLength(180),
                    Textarea::make('payment_note')->label('Комментарий о ручной выплате')->maxLength(2000),
                ])
                ->visible(fn (ReferralPayoutRequest $record): bool => $this->canManage()
                    && $record->status === ReferralPayoutRequestStatus::Approved)
                ->modalDescription('Статус будет отмечен как выплаченный. Платёж выполняется вручную вне этой системы.')
                ->action(function (ReferralPayoutRequest $record, array $data): void {
                    $reference = isset($data['payment_reference']) ? (string) $data['payment_reference'] : null;
                    $note = isset($data['payment_note']) ? (string) $data['payment_note'] : null;
                    $this->performTransition(
                        $record,
                        ReferralPayoutRequestStatus::Paid,
                        'paid-'.sha1((string) $reference.'|'.(string) $note),
                        paymentNote: $note,
                        paymentReference: $reference,
                    );
                }),
        ];
    }

    private function canManage(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(FinanceAuthorization::class)->allowsManage($actor);
    }

    private function performTransition(
        ReferralPayoutRequest $record,
        ReferralPayoutRequestStatus $target,
        string $idempotencyKey,
        ?string $reason = null,
        ?string $paymentNote = null,
        ?string $paymentReference = null,
    ): void {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        app(TransitionReferralPayoutRequest::class)->handle(
            request: $record,
            target: $target,
            actor: $actor,
            idempotencyKey: $idempotencyKey,
            reason: $reason,
            paymentNote: $paymentNote,
            paymentReference: $paymentReference,
        );
        $record->refresh();
        $this->refreshFormData(['status', 'approved_at', 'rejected_at', 'paid_at', 'rejection_reason']);
        Notification::make()->success()->title('Статус заявки обновлён')->send();
    }
}
