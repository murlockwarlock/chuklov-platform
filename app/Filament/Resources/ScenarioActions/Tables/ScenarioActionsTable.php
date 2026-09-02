<?php

namespace App\Filament\Resources\ScenarioActions\Tables;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ScenarioActionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('activity_at')
                    ->label('Дата/время')
                    ->state(fn (ScenarioAction $record): string => self::activityAt($record)),
                TextColumn::make('message_summary')
                    ->label('Сообщение / событие')
                    ->state(fn (ScenarioAction $record): string => self::messageLabel($record))
                    ->wrap(),
                TextColumn::make('recipient_summary')
                    ->label('Получатель')
                    ->state(fn (ScenarioAction $record): string => self::recipientLabel($record))
                    ->wrap(),
                TextColumn::make('status_summary')
                    ->label('Статус')
                    ->state(fn (ScenarioAction $record): string => self::statusSummary($record))
                    ->badge()
                    ->wrap(),
            ])
            ->emptyStateHeading('История сообщений пока пуста')
            ->emptyStateDescription('Здесь появятся отправленные сообщения и сообщения, которые не удалось отправить.')
            ->recordActions([
                ViewAction::make()
                    ->label('Открыть')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('Открыть подробности'),
            ]);
    }

    private static function activityAt(ScenarioAction $record): string
    {
        $date = $record->delivered_at ?? $record->scheduled_for ?? $record->created_at;

        return $date === null
            ? '—'
            : CarbonImmutable::parse($date)->setTimezone(app(OrganizationContext::class)->defaultTimezone())->format('d.m H:i');
    }

    private static function messageLabel(ScenarioAction $record): string
    {
        if ($record->kind === 'appointment_reminder') {
            return 'Напоминание о визите';
        }

        $event = $record->event?->event_name;
        $value = $event instanceof BackedEnum ? $event->value : (string) $event;

        return match ($value) {
            'booking.created' => 'Новая запись',
            'booking.confirmed' => 'Подтверждение записи',
            'booking.rescheduled' => 'Перенос записи',
            'booking.cancelled' => 'Отмена записи',
            'booking.completed' => 'После визита',
            'onboarding.started' => 'Начало оформления',
            'finance.obligation.created' => 'Задолженность за визит',
            'survey.completed' => 'Завершение теста',
            default => 'Автоматическое сообщение',
        };
    }

    private static function recipientLabel(ScenarioAction $record): string
    {
        if ($record->recipient_type === 'client') {
            return trim((string) ($record->client?->full_name ?: 'Клиент'));
        }

        return trim((string) ($record->recipientUser?->name ?: 'Специалист'));
    }

    private static function statusSummary(ScenarioAction $record): string
    {
        $status = $record->status->value;
        $label = self::statusLabel($status);

        if (! in_array($status, ['failed', 'suppressed', 'cancelled', 'retryable'], true)) {
            return $label;
        }

        $reason = self::reasonLabel(
            $record->terminal_reason
                ?: $record->deliveries->first()?->last_error_code,
        );

        return $reason === null ? $label : $label.' · '.$reason;
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'scheduled' => 'Запланировано',
            'processing' => 'Отправляется',
            'delivered' => 'Отправлено',
            'retryable' => 'Повторим позже',
            'failed', 'suppressed' => 'Не отправлено',
            'cancelled' => 'Отменено',
            default => 'Статус недоступен',
        };
    }

    private static function reasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            null, '' => null,
            'verified_identity_unavailable', 'no_available_channel', 'channel_unavailable' => 'Нет доступного Telegram',
            'provider_suppressed' => 'Получатель отключил сообщения',
            'booking_changed' => 'Запись уже изменилась',
            'current_conditions_not_met' => 'Условие больше не выполнено',
            'booking_meeting_pending' => 'Ссылка на Zoom ещё готовится',
            'template_unavailable' => 'Сообщение больше недоступно',
            'delivery_context_missing' => 'Данные записи недоступны',
            'inline_buttons_unavailable' => 'Кнопка сообщения недоступна',
            'all_channels_failed', 'delivery_execution_error', 'delivery_outcome_unknown' => 'Не удалось отправить',
            default => 'Не удалось отправить',
        };
    }
}
