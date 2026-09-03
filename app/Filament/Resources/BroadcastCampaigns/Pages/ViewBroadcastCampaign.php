<?php

namespace App\Filament\Resources\BroadcastCampaigns\Pages;

use App\Filament\Resources\BroadcastCampaigns\BroadcastCampaignResource;
use App\Models\User;
use App\Modules\Broadcasts\Application\CancelBroadcastCampaign;
use App\Modules\Broadcasts\Application\CopyBroadcastCampaign;
use App\Modules\Broadcasts\Application\PreviewBroadcastCampaign;
use App\Modules\Broadcasts\Application\StartBroadcastCampaign;
use App\Modules\Broadcasts\Application\TestBroadcastCampaign;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\View\View;

final class ViewBroadcastCampaign extends ViewRecord
{
    protected static string $resource = BroadcastCampaignResource::class;

    protected static ?string $title = 'Рассылка';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Редактировать')->visible(fn (): bool => $this->campaign()->state === BroadcastCampaignState::Draft),
            Action::make('preview')
                ->label('Предпросмотр')
                ->icon('heroicon-o-eye')
                ->modalHeading('Предпросмотр рассылки')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть')
                ->modalContent(function (): View {
                    $campaign = $this->campaign();
                    $preview = app(PreviewBroadcastCampaign::class)->message($this->actor(), $campaign);
                    $summary = $campaign->state === BroadcastCampaignState::Draft
                        ? app(PreviewBroadcastCampaign::class)->handle($this->actor(), $campaign)
                        : null;

                    return view('filament.resources.broadcasts.preview', [
                        'preview' => $preview,
                        'summary' => $summary,
                        'reasonLabels' => [
                            'marketing_consent_missing' => 'нет согласия на маркетинговые сообщения',
                            'marketing_suppressed' => 'согласие отозвано',
                            'verified_channel_unavailable' => 'нет подтверждённого канала',
                        ],
                    ]);
                }),
            Action::make('runAgain')
                ->label('Запустить снова')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn (): bool => $this->campaign()->state !== BroadcastCampaignState::Draft)
                ->action(function (): void {
                    $copy = app(CopyBroadcastCampaign::class)->handle($this->actor(), $this->campaign());
                    $this->redirect(BroadcastCampaignResource::getUrl('edit', ['record' => $copy]));
                }),
            Action::make('editAndRerun')
                ->label('Редактировать и повторить')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => $this->campaign()->state !== BroadcastCampaignState::Draft)
                ->action(function (): void {
                    $copy = app(CopyBroadcastCampaign::class)->handle($this->actor(), $this->campaign());
                    $this->redirect(BroadcastCampaignResource::getUrl('edit', ['record' => $copy]));
                }),
            Action::make('test')->label('Тестовая отправка')->icon('heroicon-o-paper-airplane')->visible(fn (): bool => $this->campaign()->state === BroadcastCampaignState::Draft)->schema([
                Select::make('test_client_id')->label('Тестовый получатель')->options(fn (): array => Client::query()->where('organization_id', app(OrganizationContext::class)->id())->whereHas('channelIdentities', fn ($query) => $query->where('channel', 'telegram')->where('verification_status', ChannelIdentityStatus::Verified->value))->orderBy('full_name')->limit(200)->pluck('full_name', 'id')->all())->searchable()->required()->helperText('Сообщение уйдёт только выбранному получателю, а не всему сегменту.'),
            ])->action(function (array $data): void {
                $recipient = app(TestBroadcastCampaign::class)->handle($this->actor(), $this->campaign(), (int) $data['test_client_id']);
                Notification::make()->title($recipient->state->value === 'delivered' ? 'Тестовое сообщение доставлено' : 'Тестовая отправка завершилась с ошибкой')->body('Тестовая отправка отмечена отдельно и не затрагивает список рассылки.')->status($recipient->state->value === 'delivered' ? 'success' : 'danger')->send();
            }),
            Action::make('start')->label(fn (): string => $this->campaign()->send_mode === 'scheduled' ? 'Запланировать' : 'Запустить рассылку')->color('primary')->requiresConfirmation()->modalDescription('Список получателей и версии сообщений будут зафиксированы. После запуска изменить рассылку нельзя.')->visible(fn (): bool => $this->campaign()->state === BroadcastCampaignState::Draft)->action(function (): void {
                app(StartBroadcastCampaign::class)->handle($this->actor(), $this->campaign());
                Notification::make()->title('Рассылка подготовлена')->body('Получатели зафиксированы; отправка начнётся в выбранное время.')->success()->send();
                $this->refreshFormData(['state', 'scheduled_at', 'audience_count', 'suppressed_count']);
            }),
            Action::make('cancel')->label('Отменить')->color('danger')->requiresConfirmation()->visible(fn (): bool => in_array($this->campaign()->state, [BroadcastCampaignState::Draft, BroadcastCampaignState::Scheduled], true))->action(function (): void {
                app(CancelBroadcastCampaign::class)->handle($this->actor(), $this->campaign());
                Notification::make()->title('Рассылка отменена')->success()->send();
                $this->refreshFormData(['state', 'cancelled_at']);
            }),
        ];
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function campaign(): BroadcastCampaign
    {
        abort_unless($this->record instanceof BroadcastCampaign, 404);

        return $this->record->refresh();
    }
}
