<?php

namespace App\Filament\Resources\BroadcastCampaigns\Pages;

use App\Filament\Resources\BroadcastCampaigns\BroadcastCampaignResource;
use App\Filament\Support\BroadcastFailurePresentation;
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
                $delivered = $recipient->state->value === 'delivered';
                $reason = $recipient->last_error_code ?: $recipient->exclusion_code;
                $body = $delivered
                    ? 'Тестовая отправка отмечена отдельно и не затрагивает список рассылки.'
                    : BroadcastFailurePresentation::label($reason);

                Notification::make()
                    ->title($delivered ? 'Тестовое сообщение доставлено' : 'Тестовая отправка завершилась с ошибкой')
                    ->body($body)
                    ->status($delivered ? 'success' : 'danger')
                    ->send();
            }),
            Action::make('start')->label(fn (): string => $this->campaign()->send_mode === 'scheduled' ? 'Запланировать' : 'Запустить рассылку')->color('primary')->requiresConfirmation()->modalDescription('Список получателей и версии сообщений будут зафиксированы. После запуска изменить рассылку нельзя.')->visible(fn (): bool => $this->campaign()->state === BroadcastCampaignState::Draft)->action(function (): void {
                $campaign = app(StartBroadcastCampaign::class)->handle($this->actor(), $this->campaign());
                $counts = 'Доставлено: '.$campaign->delivered_count.' · ошибок: '.$campaign->failed_count.' · исключено: '.$campaign->suppressed_count.'.';

                if ($campaign->state === BroadcastCampaignState::Completed) {
                    Notification::make()
                        ->title($campaign->failed_count > 0 ? 'Рассылка завершена с ошибками' : 'Рассылка отправлена')
                        ->body($counts.' Причины ошибок указаны в списке получателей.')
                        ->status($campaign->failed_count > 0 ? 'warning' : 'success')
                        ->send();
                } else {
                    Notification::make()
                        ->title($campaign->send_mode === 'scheduled' ? 'Рассылка запланирована' : 'Рассылка поставлена в очередь')
                        ->body($counts.' Итог появится после обработки очереди; причины ошибок указаны в списке получателей.')
                        ->success()
                        ->send();
                }

                $this->refreshFormData(['state', 'scheduled_at', 'audience_count', 'delivered_count', 'failed_count', 'suppressed_count']);
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
