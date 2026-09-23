<?php

namespace App\Filament\Resources\ScenarioActions;

use App\Filament\Resources\ScenarioActions\Pages\ListScenarioActions;
use App\Filament\Resources\ScenarioActions\Pages\ViewScenarioAction;
use App\Filament\Resources\ScenarioActions\Tables\ScenarioActionsTable;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Filament\Support\LocalizedResource;
use App\Models\User;
use App\Modules\Feedback\Domain\Enums\NpsBand;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioDeliveryAttempt;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ScenarioActionResource extends LocalizedResource
{
    protected static ?string $model = ScenarioAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'История сообщений';

    protected static string|\UnitEnum|null $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'сообщение истории';

    protected static ?string $pluralModelLabel = 'история сообщений';

    protected static ?string $breadcrumb = 'История сообщений';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Контекст события'))
                    ->schema([
                        TextEntry::make('business_client')
                            ->label(__('Клиент'))
                            ->state(fn (ScenarioAction $record): string => $record->client?->full_name ?: '—')
                            ->url(fn (ScenarioAction $record): ?string => CrmEntityLinks::clientUrl($record->client))
                            ->color(fn (ScenarioAction $record): ?string => CrmEntityLinks::clientUrl($record->client) === null ? null : 'primary')
                            ->visible(fn (ScenarioAction $record): bool => $record->client instanceof Client),
                        TextEntry::make('business_event')
                            ->label(__('Событие'))
                            ->state(fn (ScenarioAction $record): string => self::eventLabel($record->event?->event_name)),
                        TextEntry::make('business_reason')
                            ->label(__('Причина'))
                            ->state(fn (ScenarioAction $record): string => self::eventReason($record)),
                        TextEntry::make('business_channel')
                            ->label(__('Канал'))
                            ->state(fn (ScenarioAction $record): string => self::channelSummary($record->channel_priority)),
                        TextEntry::make('business_recipient')
                            ->label(__('Получатель'))
                            ->state(fn (ScenarioAction $record): string => self::businessRecipient($record)),
                        TextEntry::make('business_delivery')
                            ->label(__('Доставка'))
                            ->state(fn (ScenarioAction $record): string => self::deliverySummary($record)),
                        TextEntry::make('business_occurred_at')
                            ->label(__('Событие произошло'))
                            ->state(fn (ScenarioAction $record): mixed => $record->event?->occurred_at)
                            ->dateTime('d.m.Y H:i'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                TextEntry::make('rule.name')->label(__('Правило')),
                TextEntry::make('sequence_summary')
                    ->label(__('Сообщение в серии'))
                    ->state(fn (ScenarioAction $record): string => __(':current из :total', [
                        'current' => $record->sequence_number,
                        'total' => $record->max_occurrences,
                    ])),
                TextEntry::make('template_summary')
                    ->label(__('Версия сообщения'))
                    ->state(function (ScenarioAction $record): string {
                        $template = $record->templateVersion?->template;

                        return $template === null
                            ? __('Недоступно')
                            : __(':name — версия :version — :locale', [
                                'name' => $template->name ?: __('Сообщение'),
                                'version' => $record->templateVersion->version,
                                'locale' => self::localeLabel($template->locale),
                            ]);
                    }),
                TextEntry::make('recipient_summary')
                    ->label(__('Получатель'))
                    ->state(function (ScenarioAction $record): string {
                        if ($record->recipient_type === 'client') {
                            $client = $record->client;

                            return __('Клиент: :name', ['name' => $client instanceof Client ? $client->full_name : __('недоступен')]);
                        }

                        $user = $record->recipientUser;

                        return __('Сотрудник: :name', ['name' => $user instanceof User ? $user->name : __('недоступен')]);
                    })
                    ->url(fn (ScenarioAction $record): ?string => $record->recipient_type === 'client'
                        ? CrmEntityLinks::clientUrl($record->client)
                        : null)
                    ->color(fn (ScenarioAction $record): ?string => $record->recipient_type !== 'client'
                        ? null
                        : (CrmEntityLinks::clientUrl($record->client) === null ? null : 'primary')),
                TextEntry::make('purpose')
                    ->label(__('Тип сообщения'))
                    ->formatStateUsing(fn (ScenarioRulePurpose|string $state): string => self::purposeLabel($state)),
                TextEntry::make('scheduled_for')->label(__('Запланировано'))->dateTime('d.m.Y H:i'),
                TextEntry::make('status')
                    ->label(__('Статус'))
                    ->badge()
                    ->formatStateUsing(fn (ScenarioActionStatus|string $state): string => self::statusLabel($state)),
                TextEntry::make('delivered_at')->label(__('Отправлено'))->dateTime('d.m.Y H:i')->placeholder('—'),
                TextEntry::make('terminal_reason')
                    ->label(__('Результат'))
                    ->formatStateUsing(fn (?string $state): string => self::reasonLabel($state))
                    ->placeholder('—'),
                TextEntry::make('channel_order')
                    ->label(__('Способ связи'))
                    ->state(fn (ScenarioAction $record): string => self::channelSummary($record->channel_priority)),
                TextEntry::make('conditions_summary')
                    ->label(__('Условия на момент запуска'))
                    ->state(fn (ScenarioAction $record): string => self::conditionsSummary($record->condition_snapshot))
                    ->columnSpanFull(),
                TextEntry::make('delivery_history')
                    ->label(__('История отправки'))
                    ->state(fn (ScenarioAction $record): string => $record->deliveries
                        ->sortBy('priority')
                        ->map(fn (ScenarioDelivery $delivery): string => self::formatDelivery($delivery))
                        ->implode("\n"))
                    ->columnSpanFull(),
                Section::make(__('Технические детали'))
                    ->collapsed()
                    ->schema([
                        TextEntry::make('technical_action_id')
                            ->label(__('ID сообщения'))
                            ->state(fn (ScenarioAction $record): string => (string) $record->getKey()),
                        TextEntry::make('technical_event_id')
                            ->label(__('ID события'))
                            ->state(fn (ScenarioAction $record): string => (string) $record->scenario_event_id),
                        TextEntry::make('technical_rule_id')
                            ->label(__('ID авто-сообщения'))
                            ->state(fn (ScenarioAction $record): string => (string) $record->scenario_rule_id),
                        TextEntry::make('technical_booking_id')
                            ->label(__('ID записи'))
                            ->state(fn (ScenarioAction $record): string => $record->booking_id === null ? '—' : (string) $record->booking_id),
                        TextEntry::make('technical_rule_key')
                            ->label(__('Ключ авто-сообщения'))
                            ->state(fn (ScenarioAction $record): string => $record->rule?->rule_key ?: '—'),
                        TextEntry::make('technical_event_key')
                            ->label(__('Системное событие'))
                            ->state(fn (ScenarioAction $record): string => $record->event?->event_name?->value ?: '—'),
                        TextEntry::make('technical_delivery_attempts')
                            ->label(__('Попыток отправки'))
                            ->state(fn (ScenarioAction $record): string => (string) $record->deliveries->sum('attempt_count')),
                        TextEntry::make('technical_error_codes')
                            ->label(__('Коды ошибок'))
                            ->state(fn (ScenarioAction $record): string => self::errorCodes($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ScenarioActionsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ViewScenarios,
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with([
                'event',
                'rule',
                'templateVersion.template',
                'client',
                'recipientUser',
                'appointmentReminder',
                'deliveries.attempts',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScenarioActions::route('/'),
            'view' => ViewScenarioAction::route('/{record}'),
        ];
    }

    private static function formatDelivery(ScenarioDelivery $delivery): string
    {
        $attempts = $delivery->attempts
            ->sortBy('attempt_number')
            ->map(fn (ScenarioDeliveryAttempt $attempt): string => __('Попытка :number: :outcome', [
                'number' => $attempt->attempt_number,
                'outcome' => self::attemptLabel($attempt->outcome->value),
            ]))
            ->implode(', ');

        return __(':priority. :channel — :status — :attempts', [
            'priority' => $delivery->priority + 1,
            'channel' => self::channelLabel($delivery->channel),
            'status' => self::deliveryLabel($delivery->status),
            'attempts' => $attempts === '' ? __('попыток ещё не было') : $attempts,
        ]);
    }

    private static function eventLabel(mixed $event): string
    {
        $value = $event instanceof BackedEnum ? $event->value : (string) $event;

        return match ($value) {
            'booking.created' => __('После новой записи'),
            'booking.confirmed' => __('После подтверждения записи'),
            'booking.rescheduled' => __('После переноса записи'),
            'booking.cancelled' => __('После отмены записи'),
            'booking.rejected' => __('После отклонения записи'),
            'booking.completed' => __('После завершения визита'),
            'onboarding.started' => __('После начала оформления'),
            'finance.obligation.created' => __('После появления задолженности'),
            'companion.requested_specialist' => __('Клиент запросил специалиста'),
            'companion.fallback_failed' => __('Когда AI не смог ответить'),
            'broadcast.delivery_failed' => __('При сбое операционной рассылки'),
            'feedback.submitted' => __('После обратной связи клиента'),
            'referral.payout.requested' => __('При запросе выплаты партнёра'),
            'referral.payout.status_changed' => __('При изменении статуса выплаты'),
            'booking.home_visit.changed' => __('При изменении выездного визита'),
            'ai.evaluation.failed' => __('При сбое проверки AI'),
            'referral.link.visited' => __('При переходе по реферальной ссылке'),
            'payment.provider.event.prepared' => __('Устаревшее событие платёжного провайдера'),
            'finance.payment.succeeded' => __('После подтверждённой оплаты'),
            'finance.payment.failed' => __('При неуспешной оплате'),
            'finance.payment.initiation_unavailable' => __('Когда онлайн-оплата недоступна'),
            'finance.payment.reconciliation_required' => __('Когда платёж требует сверки'),
            'commerce.fulfillment.failed' => __('Если доступ не выдан'),
            'commerce.fulfillment.completed' => __('Когда доступ выдан'),
            'referral.reward.earned' => __('При начислении по партнёрской программе'),
            default => __('Событие'),
        };
    }

    private static function statusLabel(ScenarioActionStatus|string $status): string
    {
        $status = $status instanceof ScenarioActionStatus ? $status : ScenarioActionStatus::tryFrom($status);

        return CrmLabel::enum($status) ?? __('Неизвестный статус');
    }

    private static function purposeLabel(ScenarioRulePurpose|string $purpose): string
    {
        $purpose = $purpose instanceof ScenarioRulePurpose ? $purpose : ScenarioRulePurpose::tryFrom($purpose);

        return CrmLabel::enum($purpose) ?? __('Не указано');
    }

    private static function deliveryLabel(ScenarioDeliveryStatus|string $status): string
    {
        $status = $status instanceof ScenarioDeliveryStatus ? $status : ScenarioDeliveryStatus::tryFrom($status);

        return CrmLabel::enum($status) ?? __('Неизвестный статус');
    }

    private static function attemptLabel(string $outcome): string
    {
        return match ($outcome) {
            'delivered' => __('отправлено'),
            'retryable' => __('повторим позже'),
            'permanent_failure' => __('не отправлено'),
            'unavailable' => __('канал недоступен'),
            'suppressed' => __('получатель отключил сообщения'),
            'in_flight' => __('результат не определён'),
            default => __('результат не определён'),
        };
    }

    private static function channelLabel(string $channel): string
    {
        return $channel === 'telegram' ? 'Telegram' : __('Другой способ связи');
    }

    /** @param list<string> $channels */
    private static function channelSummary(array $channels): string
    {
        return implode(' → ', array_map(static fn (string $channel): string => self::channelLabel($channel), $channels));
    }

    private static function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'current_conditions_not_met' => __('Условие больше не выполнено'),
            'provider_suppressed' => __('Получатель отключил сообщения'),
            'recipient_unavailable', 'verified_identity_unavailable', 'no_available_channel', 'channel_unavailable' => __('Нет доступного Telegram'),
            'booking_changed' => __('Запись уже изменилась'),
            'booking_meeting_pending' => __('Ссылка на Zoom ещё готовится'),
            'template_unavailable' => __('Сообщение больше недоступно'),
            null => '—',
            default => __('Не удалось отправить'),
        };
    }

    private static function eventReason(ScenarioAction $record): string
    {
        $reason = $record->render_context['companion']['reason']
            ?? $record->event?->payload['reason']
            ?? $record->terminal_reason;

        return match ((string) $reason) {
            'human_requested' => __('Клиент явно попросил специалиста'),
            'urgent_safety_concern' => __('Обнаружена срочная ситуация, требующая специалиста'),
            'out_of_scope' => __('Вопрос не входит в безопасный сценарий AI'),
            'repeated_execution_failure' => __('AI не смог ответить после повторной ошибки'),
            'verified_identity_unavailable', 'no_available_channel', 'channel_unavailable' => __('Для получателя нет доступного канала'),
            '' => '—',
            default => self::reasonLabel(is_string($reason) ? $reason : null),
        };
    }

    private static function businessRecipient(ScenarioAction $record): string
    {
        if ($record->recipient_type === 'client') {
            return $record->client?->full_name ?: __('Клиент недоступен');
        }

        return $record->recipientUser?->name ?: __('Сотрудник недоступен');
    }

    private static function deliverySummary(ScenarioAction $record): string
    {
        if ($record->deliveries->isEmpty()) {
            return __(':status — доставка ещё не создана', ['status' => self::statusLabel($record->status)]);
        }

        return $record->deliveries
            ->sortBy('priority')
            ->map(fn (ScenarioDelivery $delivery): string => self::channelLabel($delivery->channel).' — '.self::deliveryLabel($delivery->status))
            ->implode('; ');
    }

    private static function errorCodes(ScenarioAction $record): string
    {
        $codes = $record->deliveries
            ->flatMap(fn (ScenarioDelivery $delivery) => $delivery->attempts->pluck('error_code')->push($delivery->last_error_code))
            ->filter()
            ->unique()
            ->values();

        return $codes->isEmpty() ? __('Нет') : $codes->implode(', ');
    }

    private static function localeLabel(?string $locale): string
    {
        return match ($locale) {
            'ru' => __('русский'),
            'en' => __('английский'),
            default => __('другой язык'),
        };
    }

    private static function conditionsSummary(mixed $conditions): string
    {
        if (! is_array($conditions) || $conditions === []) {
            return __('Без дополнительного условия');
        }

        return collect($conditions)->map(function (mixed $condition): string {
            if (! is_array($condition)) {
                return __('Условие');
            }

            $type = match ($condition['type'] ?? null) {
                'booking.status' => __('статус записи'),
                'booking.has_qualifying_next_booking' => __('подходящая следующая запись'),
                'client.language' => __('язык клиента'),
                'client.marketing_consent' => __('согласие на маркетинговые сообщения'),
                'feedback.band' => __('категория оценки'),
                'onboarding.completed' => __('завершение оформления'),
                'onboarding.stage' => __('этап оформления'),
                'payment.is_pre_visit_booking_payment' => __('оплата записи до визита'),
                'survey.available' => __('доступность теста'),
                'survey.progress_available' => __('доступность сравнения тестов'),
                default => __('условие'),
            };
            $operator = match ($condition['operator'] ?? null) {
                'equals' => __('равно'),
                'not_equals' => __('не равно'),
                'in' => __('одно из'),
                'exists' => __('заполнено'),
                default => __('проверяется'),
            };

            return ucfirst($type).' '.$operator.(array_key_exists('value', $condition) ? ' '.self::conditionValue($condition['value']) : '');
        })->implode('; ');
    }

    private static function conditionValue(mixed $value): string
    {
        $values = is_array($value) ? $value : [$value];

        return collect($values)->map(static fn (mixed $item): string => match ((string) $item) {
            'true' => __('да'),
            'false' => __('нет'),
            NpsBand::Positive->value => __('положительная'),
            NpsBand::Internal->value => __('внутренняя'),
            'contacts' => __('контакты'),
            'profile' => __('профиль'),
            'service' => __('услуга'),
            'goals' => __('цели'),
            default => (string) $item,
        })->implode(', ');
    }
}
