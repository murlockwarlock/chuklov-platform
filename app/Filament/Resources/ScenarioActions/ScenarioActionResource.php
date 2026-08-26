<?php

namespace App\Filament\Resources\ScenarioActions;

use App\Filament\Resources\ScenarioActions\Pages\ListScenarioActions;
use App\Filament\Resources\ScenarioActions\Pages\ViewScenarioAction;
use App\Filament\Resources\ScenarioActions\Tables\ScenarioActionsTable;
use App\Models\User;
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
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ScenarioActionResource extends Resource
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
                TextEntry::make('event.event_name')
                    ->label('Когда')
                    ->formatStateUsing(fn (mixed $state): string => self::eventLabel($state)),
                TextEntry::make('event.occurred_at')->label('Событие произошло')->dateTime('d.m.Y H:i'),
                TextEntry::make('rule.name')->label('Правило'),
                TextEntry::make('sequence_summary')
                    ->label('Сообщение в серии')
                    ->state(fn (ScenarioAction $record): string => $record->sequence_number.' из '.$record->max_occurrences),
                TextEntry::make('template_summary')
                    ->label('Версия сообщения')
                    ->state(function (ScenarioAction $record): string {
                        $template = $record->templateVersion?->template;

                        return $template === null
                            ? 'Недоступно'
                            : ($template->name ?: 'Сообщение').' — версия '.$record->templateVersion->version.' — '.self::localeLabel($template->locale);
                    }),
                TextEntry::make('recipient_summary')
                    ->label('Получатель')
                    ->state(function (ScenarioAction $record): string {
                        if ($record->recipient_type === 'client') {
                            $client = $record->client;

                            return 'Клиент: '.($client instanceof Client ? $client->full_name : 'недоступен');
                        }

                        $user = $record->recipientUser;

                        return 'Сотрудник: '.($user instanceof User ? $user->name : 'недоступен');
                    }),
                TextEntry::make('purpose')
                    ->label('Назначение')
                    ->formatStateUsing(fn (ScenarioRulePurpose|string $state): string => self::purposeLabel($state)),
                TextEntry::make('scheduled_for')->label('Запланировано')->dateTime('d.m.Y H:i'),
                TextEntry::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (ScenarioActionStatus|string $state): string => self::statusLabel($state)),
                TextEntry::make('delivered_at')->label('Отправлено')->dateTime('d.m.Y H:i')->placeholder('—'),
                TextEntry::make('terminal_reason')
                    ->label('Результат')
                    ->formatStateUsing(fn (?string $state): string => self::reasonLabel($state))
                    ->placeholder('—'),
                TextEntry::make('channel_order')
                    ->label('Способ связи')
                    ->state(fn (ScenarioAction $record): string => self::channelSummary($record->channel_priority)),
                TextEntry::make('conditions_summary')
                    ->label('Условия на момент запуска')
                    ->state(fn (ScenarioAction $record): string => self::conditionsSummary($record->condition_snapshot))
                    ->columnSpanFull(),
                TextEntry::make('delivery_history')
                    ->label('История отправки')
                    ->state(fn (ScenarioAction $record): string => $record->deliveries
                        ->sortBy('priority')
                        ->map(fn (ScenarioDelivery $delivery): string => self::formatDelivery($delivery))
                        ->implode("\n"))
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
            ->map(fn (ScenarioDeliveryAttempt $attempt): string => 'Попытка '.$attempt->attempt_number.': '.self::attemptLabel($attempt->outcome->value))
            ->implode(', ');

        return ($delivery->priority + 1).'. '.self::channelLabel($delivery->channel).' — '.self::deliveryLabel($delivery->status).' — '.($attempts === '' ? 'попыток ещё не было' : $attempts);
    }

    private static function eventLabel(mixed $event): string
    {
        $value = $event instanceof BackedEnum ? $event->value : (string) $event;

        return match ($value) {
            'booking.completed' => 'После завершения визита',
            'onboarding.started' => 'После начала оформления',
            'finance.obligation.created' => 'После появления задолженности',
            default => 'Событие',
        };
    }

    private static function statusLabel(ScenarioActionStatus|string $status): string
    {
        $status = $status instanceof ScenarioActionStatus ? $status : ScenarioActionStatus::tryFrom($status);

        return match ($status) {
            ScenarioActionStatus::Scheduled => 'Запланировано',
            ScenarioActionStatus::Processing => 'Отправляется',
            ScenarioActionStatus::Delivered => 'Отправлено',
            ScenarioActionStatus::Retryable => 'Повторим позже',
            ScenarioActionStatus::Failed, ScenarioActionStatus::Suppressed => 'Не отправлено',
            ScenarioActionStatus::Cancelled => 'Отменено',
            default => 'Неизвестный статус',
        };
    }

    private static function purposeLabel(ScenarioRulePurpose|string $purpose): string
    {
        $purpose = $purpose instanceof ScenarioRulePurpose ? $purpose : ScenarioRulePurpose::tryFrom($purpose);

        return match ($purpose) {
            ScenarioRulePurpose::Service => 'Сервисное сообщение',
            ScenarioRulePurpose::Transactional => 'Системное сообщение',
            default => 'Не указано',
        };
    }

    private static function deliveryLabel(ScenarioDeliveryStatus|string $status): string
    {
        $status = $status instanceof ScenarioDeliveryStatus ? $status : ScenarioDeliveryStatus::tryFrom($status);

        return match ($status) {
            ScenarioDeliveryStatus::Pending => 'Ожидает отправки',
            ScenarioDeliveryStatus::Processing => 'Отправляется',
            ScenarioDeliveryStatus::Delivered => 'Отправлено',
            ScenarioDeliveryStatus::Retryable => 'Повторим позже',
            ScenarioDeliveryStatus::PermanentFailure => 'Не отправлено',
            ScenarioDeliveryStatus::Unavailable => 'Канал недоступен',
            ScenarioDeliveryStatus::Suppressed => 'Получатель отключил сообщения',
            default => 'Неизвестный статус',
        };
    }

    private static function attemptLabel(string $outcome): string
    {
        return match ($outcome) {
            'delivered' => 'отправлено',
            'retryable' => 'повторим позже',
            'permanent_failure' => 'не отправлено',
            'unavailable' => 'канал недоступен',
            'suppressed' => 'получатель отключил сообщения',
            'in_flight' => 'результат не определён',
            default => 'результат не определён',
        };
    }

    private static function channelLabel(string $channel): string
    {
        return $channel === 'telegram' ? 'Telegram' : 'Другой способ связи';
    }

    /** @param list<string> $channels */
    private static function channelSummary(array $channels): string
    {
        return implode(' → ', array_map(static fn (string $channel): string => self::channelLabel($channel), $channels));
    }

    private static function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'current_conditions_not_met' => 'Условие больше не выполнено',
            'provider_suppressed' => 'Получатель отключил сообщения',
            'recipient_unavailable' => 'Получатель недоступен',
            'no_available_channel' => 'Нет доступного подтверждённого канала',
            null => '—',
            default => 'Не удалось отправить',
        };
    }

    private static function localeLabel(?string $locale): string
    {
        return match ($locale) {
            'ru' => 'русский',
            'en' => 'английский',
            default => 'другой язык',
        };
    }

    private static function conditionsSummary(mixed $conditions): string
    {
        if (! is_array($conditions) || $conditions === []) {
            return 'Без дополнительного условия';
        }

        return collect($conditions)->map(function (mixed $condition): string {
            if (! is_array($condition)) {
                return 'Условие';
            }

            $type = match ($condition['type'] ?? null) {
                'booking.status' => 'статус записи',
                'booking.has_qualifying_next_booking' => 'подходящая следующая запись',
                'client.language' => 'язык клиента',
                'client.marketing_consent' => 'согласие на маркетинговые сообщения',
                'onboarding.completed' => 'завершение оформления',
                'onboarding.stage' => 'этап оформления',
                default => 'условие',
            };
            $operator = match ($condition['operator'] ?? null) {
                'equals' => 'равно',
                'not_equals' => 'не равно',
                'in' => 'одно из',
                'exists' => 'заполнено',
                default => 'проверяется',
            };

            return ucfirst($type).' '.$operator.(array_key_exists('value', $condition) ? ' '.self::conditionValue($condition['value']) : '');
        })->implode('; ');
    }

    private static function conditionValue(mixed $value): string
    {
        $values = is_array($value) ? $value : [$value];

        return collect($values)->map(static fn (mixed $item): string => match ((string) $item) {
            'true' => 'да',
            'false' => 'нет',
            'contacts' => 'контакты',
            'profile' => 'профиль',
            'service' => 'услуга',
            'goals' => 'цели',
            default => (string) $item,
        })->implode(', ');
    }
}
