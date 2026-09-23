<?php

namespace App\Filament\Resources\BroadcastCampaigns\Pages;

use App\Filament\Resources\BroadcastCampaigns\BroadcastCampaignResource;
use App\Filament\Support\BroadcastFailurePresentation;
use App\Filament\Support\LocalizedViewRecord;
use App\Models\User;
use App\Modules\Broadcasts\Application\CancelBroadcastCampaign;
use App\Modules\Broadcasts\Application\CopyBroadcastCampaign;
use App\Modules\Broadcasts\Application\PreviewBroadcastCampaign;
use App\Modules\Broadcasts\Application\StartBroadcastCampaign;
use App\Modules\Broadcasts\Application\TestBroadcastCampaign;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

final class ViewBroadcastCampaign extends LocalizedViewRecord
{
    protected static string $resource = BroadcastCampaignResource::class;

    protected static ?string $title = 'Рассылка';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label(__('Редактировать'))->visible(fn (): bool => $this->campaign()->state === BroadcastCampaignState::Draft),
            Action::make('preview')
                ->label(__('Предпросмотр'))
                ->icon('heroicon-o-eye')
                ->modalHeading(__('Предпросмотр рассылки'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Закрыть'))
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
                            'marketing_consent_missing' => __('нет согласия на маркетинговые сообщения'),
                            'marketing_suppressed' => __('согласие отозвано'),
                            'verified_channel_unavailable' => __('нет подтверждённого канала'),
                        ],
                    ]);
                }),
            Action::make('runAgain')
                ->label(__('Запустить снова'))
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription(__('Будет создана копия этой рассылки и сразу запущена. После запуска изменить её нельзя.'))
                ->visible(fn (): bool => $this->campaign()->state !== BroadcastCampaignState::Draft)
                ->action(function (): void {
                    try {
                        $copy = app(CopyBroadcastCampaign::class)->handle($this->actor(), $this->campaign());
                        $campaign = app(StartBroadcastCampaign::class)->handle($this->actor(), $copy);
                    } catch (ValidationException $exception) {
                        $message = __((string) (collect($exception->errors())->flatten()->first() ?: 'Повторный запуск не выполнен.'));

                        Notification::make()
                            ->title(__('Повторный запуск не выполнен'))
                            ->body($message)
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->notifyAboutStartedCampaign($campaign);
                    $this->redirect(BroadcastCampaignResource::getUrl('view', ['record' => $campaign]));
                }),
            Action::make('editAndRerun')
                ->label(__('Редактировать и повторить'))
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => $this->campaign()->state !== BroadcastCampaignState::Draft)
                ->action(function (): void {
                    $copy = app(CopyBroadcastCampaign::class)->handle($this->actor(), $this->campaign());
                    $this->redirect(BroadcastCampaignResource::getUrl('edit', ['record' => $copy]));
                }),
            Action::make('test')
                ->label(__('Тестовая отправка'))
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $this->campaign()->state === BroadcastCampaignState::Draft)
                ->schema([
                    Select::make('test_client_id')
                        ->label(__('Тестовый получатель'))
                        ->options(fn (): array => app(TestBroadcastCampaign::class)
                            ->eligibleTestClients($this->actor(), $this->campaign())
                            ->pluck('full_name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->helperText(__('Доступны только клиенты с согласием на маркетинговые сообщения и подтверждённым Telegram.')),
                ])
                ->action(function (array $data): void {
                    try {
                        $recipient = app(TestBroadcastCampaign::class)->handle($this->actor(), $this->campaign(), (int) $data['test_client_id']);
                    } catch (ValidationException $exception) {
                        $message = __((string) (collect($exception->errors())->flatten()->first() ?: 'Проверьте получателя и настройки рассылки.'));

                        Notification::make()
                            ->title(__('Тестовая отправка не выполнена'))
                            ->body($message)
                            ->danger()
                            ->send();

                        return;
                    }

                    $delivered = $recipient->state->value === 'delivered';
                    $reason = $recipient->last_error_code ?: $recipient->exclusion_code;
                    $body = $delivered
                        ? __('Тестовая отправка отмечена отдельно и не затрагивает список рассылки.')
                        : BroadcastFailurePresentation::label($reason);

                    Notification::make()
                        ->title($delivered ? __('Тестовое сообщение доставлено') : __('Тестовая отправка завершилась с ошибкой'))
                        ->body($body)
                        ->status($delivered ? 'success' : 'danger')
                        ->send();
                }),
            Action::make('start')->label(fn (): string => $this->campaign()->send_mode === 'scheduled' ? __('Запланировать') : __('Запустить рассылку'))->color('primary')->requiresConfirmation()->modalDescription(__('Список получателей и версии сообщений будут зафиксированы. После запуска изменить рассылку нельзя.'))->visible(fn (): bool => $this->campaign()->state === BroadcastCampaignState::Draft)->action(function (): void {
                $campaign = app(StartBroadcastCampaign::class)->handle($this->actor(), $this->campaign());
                $this->notifyAboutStartedCampaign($campaign);

                $this->refreshFormData(['state', 'scheduled_at', 'audience_count', 'delivered_count', 'failed_count', 'suppressed_count']);
            }),
            Action::make('cancel')->label(__('Отменить'))->color('danger')->requiresConfirmation()->visible(fn (): bool => in_array($this->campaign()->state, [BroadcastCampaignState::Draft, BroadcastCampaignState::Scheduled], true))->action(function (): void {
                app(CancelBroadcastCampaign::class)->handle($this->actor(), $this->campaign());
                Notification::make()->title(__('Рассылка отменена'))->success()->send();
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

    private function notifyAboutStartedCampaign(BroadcastCampaign $campaign): void
    {
        $counts = __('Доставлено: :delivered · ошибок: :failed · исключено: :suppressed.', [
            'delivered' => $campaign->delivered_count,
            'failed' => $campaign->failed_count,
            'suppressed' => $campaign->suppressed_count,
        ]);

        if ($campaign->state === BroadcastCampaignState::Completed) {
            Notification::make()
                ->title($campaign->failed_count > 0 ? __('Рассылка завершена с ошибками') : __('Рассылка отправлена'))
                ->body($counts.' '.__('Причины ошибок указаны в списке получателей.'))
                ->status($campaign->failed_count > 0 ? 'warning' : 'success')
                ->send();

            return;
        }

        Notification::make()
            ->title($campaign->send_mode === 'scheduled' ? __('Рассылка запланирована') : __('Рассылка поставлена в очередь'))
            ->body($counts.' '.__('Итог появится после обработки очереди; причины ошибок указаны в списке получателей.'))
            ->success()
            ->send();
    }
}
