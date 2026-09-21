<?php

namespace App\Filament\Resources\ScenarioActions\Tables;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
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
        $canViewClients = ClientResource::canViewAny();

        return $table
            ->stackedOnMobile()
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('activity_at')
                    ->label(__('Дата/время'))
                    ->state(fn (ScenarioAction $record): string => self::activityAt($record)),
                TextColumn::make('message_summary')
                    ->label(__('Сообщение / событие'))
                    ->state(fn (ScenarioAction $record): string => self::messageLabel($record))
                    ->wrap(),
                TextColumn::make('recipient_summary')
                    ->label(__('Получатель'))
                    ->state(fn (ScenarioAction $record): string => self::recipientLabel($record))
                    ->wrap()
                    ->url(fn (ScenarioAction $record): ?string => $record->recipient_type === 'client'
                        ? CrmEntityLinks::clientUrl($record->client, $canViewClients)
                        : null)
                    ->color(fn (ScenarioAction $record): ?string => $record->recipient_type !== 'client'
                        ? null
                        : (CrmEntityLinks::clientUrl($record->client, $canViewClients) === null ? null : 'primary'))
                    ->disabledClick(fn (ScenarioAction $record): bool => $record->recipient_type !== 'client'
                        || CrmEntityLinks::clientUrl($record->client, $canViewClients) === null),
                TextColumn::make('status_summary')
                    ->label(__('Статус'))
                    ->state(fn (ScenarioAction $record): string => self::statusSummary($record))
                    ->badge()
                    ->wrap(),
            ])
            ->emptyStateHeading(__('История сообщений пока пуста'))
            ->emptyStateDescription(__('Здесь появятся отправленные сообщения и сообщения, которые не удалось отправить.'))
            ->recordActions([
                ViewAction::make()
                    ->label(__('Открыть'))
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip(__('Открыть подробности')),
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
            return __('Напоминание о визите');
        }

        $event = $record->event?->event_name;
        $value = $event instanceof BackedEnum ? $event->value : (string) $event;

        return match ($value) {
            'booking.created' => __('Новая запись'),
            'booking.confirmed' => __('Подтверждение записи'),
            'booking.rescheduled' => __('Перенос записи'),
            'booking.cancelled' => __('Отмена записи'),
            'booking.completed' => __('После визита'),
            'onboarding.started' => __('Начало оформления'),
            'finance.obligation.created' => __('Задолженность за визит'),
            'survey.completed' => __('Завершение теста'),
            'finance.payment.succeeded' => __('Оплата получена'),
            'finance.payment.failed' => __('Оплата не прошла'),
            'finance.payment.initiation_unavailable' => __('Онлайн-оплата недоступна'),
            'finance.payment.reconciliation_required' => __('Платёж требует сверки'),
            'commerce.fulfillment.failed' => __('Доступ не выдан'),
            'commerce.fulfillment.completed' => __('Доступ выдан'),
            'referral.reward.earned' => __('Начисление по партнёрской программе'),
            default => __('Автоматическое сообщение'),
        };
    }

    private static function recipientLabel(ScenarioAction $record): string
    {
        if ($record->recipient_type === 'client') {
            return trim((string) ($record->client?->full_name ?: __('Клиент')));
        }

        return trim((string) ($record->recipientUser?->name ?: __('Специалист')));
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
        return CrmLabel::enum(ScenarioActionStatus::tryFrom($status)) ?? __('Статус недоступен');
    }

    private static function reasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            null, '' => null,
            'verified_identity_unavailable', 'no_available_channel', 'channel_unavailable' => __('Нет доступного Telegram'),
            'provider_suppressed' => __('Получатель отключил сообщения'),
            'booking_changed' => __('Запись уже изменилась'),
            'current_conditions_not_met' => __('Условие больше не выполнено'),
            'booking_meeting_pending' => __('Ссылка на Zoom ещё готовится'),
            'template_unavailable' => __('Сообщение больше недоступно'),
            'delivery_context_missing' => __('Данные записи недоступны'),
            'inline_buttons_unavailable' => __('Кнопка сообщения недоступна'),
            'all_channels_failed', 'delivery_execution_error', 'delivery_outcome_unknown' => __('Не удалось отправить'),
            default => __('Не удалось отправить'),
        };
    }
}
