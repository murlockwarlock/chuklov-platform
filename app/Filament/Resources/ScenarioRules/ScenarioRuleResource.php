<?php

namespace App\Filament\Resources\ScenarioRules;

use App\Filament\Resources\ScenarioRules\Pages\CreateScenarioRule;
use App\Filament\Resources\ScenarioRules\Pages\EditScenarioRule;
use App\Filament\Resources\ScenarioRules\Pages\ListScenarioRules;
use App\Filament\Resources\ScenarioRules\Pages\ViewScenarioRule;
use App\Filament\Resources\ScenarioRules\Schemas\ScenarioRuleForm;
use App\Filament\Resources\ScenarioRules\Tables\ScenarioRulesTable;
use App\Filament\Support\CrmLabel;
use App\Filament\Support\LocalizedResource;
use App\Models\User;
use App\Modules\Feedback\Domain\Enums\NpsBand;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ScenarioRuleResource extends LocalizedResource
{
    protected static ?string $model = ScenarioRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Авто-сообщения';

    protected static string|\UnitEnum|null $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'авто-сообщение';

    protected static ?string $pluralModelLabel = 'авто-сообщения';

    protected static ?string $breadcrumb = 'Авто-сообщения';

    public static function form(Schema $schema): Schema
    {
        return ScenarioRuleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label(__('Название')),
                TextEntry::make('trigger_event')
                    ->label(__('Когда'))
                    ->formatStateUsing(fn (mixed $state): string => self::eventLabel($state)),
                TextEntry::make('is_enabled')->label(__('Активно'))->formatStateUsing(fn (bool $state): string => $state ? __('Да') : __('Нет')),
                TextEntry::make('delay_summary')
                    ->label(__('Через'))
                    ->state(fn (ScenarioRule $record): string => __(':delay :unit', [
                        'delay' => $record->delay_value,
                        'unit' => self::delayUnitLabel($record->delay_unit),
                    ])),
                TextEntry::make('repeat_summary')
                    ->label(__('Повторения'))
                    ->state(fn (ScenarioRule $record): string => $record->max_occurrences > 1
                        ? __('До :count отправок, каждые :interval :unit', [
                            'count' => $record->max_occurrences,
                            'interval' => $record->repeat_interval_value,
                            'unit' => self::delayUnitLabel($record->repeat_interval_unit),
                        ])
                        : __('Одно сообщение')),
                TextEntry::make('template_summary')
                    ->label(__('Сообщение'))
                    ->state(function (ScenarioRule $record): string {
                        $template = $record->templateVersion?->template;

                        if ($template === null) {
                            return __('Не выбрано');
                        }

                        return ($template->name ?: __('Не выбрано')).' — '.self::localeLabel($template->locale)
                            .' · '.__('Версия: :version', ['version' => $record->templateVersion->version]);
                    }),
                TextEntry::make('recipient_summary')
                    ->label(__('Кому'))
                    ->state(fn (ScenarioRule $record): string => self::recipientSummary($record->recipient_strategy))
                    ->columnSpanFull(),
                Section::make(__('Дополнительные настройки'))
                    ->schema([
                        TextEntry::make('purpose')
                            ->label(__('Тип сообщения'))
                            ->formatStateUsing(fn (ScenarioRulePurpose|string $state): string => self::purposeLabel($state)),
                        TextEntry::make('conditions_summary')
                            ->label(__('Дополнительные условия'))
                            ->state(fn (ScenarioRule $record): string => self::conditionsSummary($record->conditions))
                            ->columnSpanFull(),
                        TextEntry::make('channel_priority')
                            ->label(__('Способ связи'))
                            ->formatStateUsing(fn (mixed $state): string => self::channelSummary($state))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                TextEntry::make('actions_count')->label(__('Отправок')),
                TextEntry::make('created_at')->label(__('Создано'))->dateTime('d.m.Y H:i'),
                TextEntry::make('updated_at')->label(__('Изменено'))->dateTime('d.m.Y H:i'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ScenarioRulesTable::configure($table);
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
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageScenarios,
        );
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('system_managed', false)
            ->with(['templateVersion.template'])
            ->withCount('actions');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScenarioRules::route('/'),
            'create' => CreateScenarioRule::route('/create'),
            'view' => ViewScenarioRule::route('/{record}'),
            'edit' => EditScenarioRule::route('/{record}/edit'),
        ];
    }

    private static function delayUnitLabel(ScenarioDelayUnit|string|null $unit): string
    {
        $unit = $unit instanceof ScenarioDelayUnit ? $unit : ScenarioDelayUnit::tryFrom((string) $unit);

        if ($unit === null) {
            return '';
        }

        if ($unit->value === 'minutes') {
            return __('мин.');
        }

        if ($unit->value === 'hours') {
            return __('ч.');
        }

        return __('дн.');
    }

    private static function eventLabel(mixed $event): string
    {
        $value = $event instanceof BackedEnum ? $event->value : (string) $event;

        return match ($value) {
            ScenarioEventType::BookingCreated->value => __('После новой записи'),
            ScenarioEventType::BookingConfirmed->value => __('После подтверждения записи'),
            ScenarioEventType::BookingRescheduled->value => __('После переноса записи'),
            ScenarioEventType::BookingCancelled->value => __('После отмены записи'),
            ScenarioEventType::BookingRejected->value => __('После отклонения записи'),
            ScenarioEventType::BookingCompleted->value => __('После завершения визита'),
            ScenarioEventType::OnboardingStarted->value => __('После начала оформления'),
            ScenarioEventType::FinancialObligationCreated->value => __('После появления задолженности'),
            ScenarioEventType::FinancialDebtReminderRequested->value => __('При отправке напоминания о задолженности'),
            ScenarioEventType::SurveyCompleted->value => __('После завершения теста'),
            ScenarioEventType::TestStagnationDetected->value => __('При отсутствии снижения показателей'),
            ScenarioEventType::CompanionRequestedSpecialist->value => __('Когда клиент просит специалиста'),
            ScenarioEventType::CompanionFallbackFailed->value => __('Когда AI не смог ответить'),
            ScenarioEventType::BroadcastDeliveryFailed->value => __('При сбое операционной рассылки'),
            ScenarioEventType::ClientFeedbackSubmitted->value => __('После обратной связи клиента'),
            ScenarioEventType::PayoutRequested->value => __('При запросе выплаты партнёра'),
            ScenarioEventType::PayoutStatusChanged->value => __('При изменении статуса выплаты'),
            ScenarioEventType::HomeVisitChanged->value => __('При изменении выездного визита'),
            ScenarioEventType::AiEvaluationFailed->value => __('При сбое проверки AI'),
            ScenarioEventType::KnowledgeIngestionFailed->value => __('При ошибке обработки материала'),
            ScenarioEventType::ReferralLinkVisited->value => __('При переходе по реферальной ссылке'),
            ScenarioEventType::PaymentProviderEventPrepared->value => __('Устаревшее событие платёжного провайдера'),
            ScenarioEventType::PaymentSucceeded->value => __('После подтверждённой оплаты'),
            ScenarioEventType::PaymentFailed->value => __('При неуспешной оплате'),
            ScenarioEventType::PaymentInitiationUnavailable->value => __('Когда онлайн-оплата недоступна'),
            ScenarioEventType::PaymentReconciliationRequired->value => __('Когда платёж требует сверки'),
            ScenarioEventType::FulfillmentFailed->value => __('Если доступ не выдан'),
            ScenarioEventType::FulfillmentCompleted->value => __('Когда доступ выдан'),
            ScenarioEventType::ReferralRewardEarned->value => __('При начислении по партнёрской программе'),
            default => __('Событие'),
        };
    }

    private static function purposeLabel(ScenarioRulePurpose|string $purpose): string
    {
        $purpose = $purpose instanceof ScenarioRulePurpose ? $purpose : ScenarioRulePurpose::tryFrom($purpose);

        return CrmLabel::enum($purpose) ?? __('Не указано');
    }

    private static function localeLabel(?string $locale): string
    {
        return match ($locale) {
            'ru' => __('Русский'),
            'en' => __('Английский'),
            default => __('Другой язык'),
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
                'onboarding.completed' => __('завершение оформления'),
                'onboarding.stage' => __('этап оформления'),
                'finance.has_outstanding_debt' => __('непогашенная задолженность'),
                'feedback.band' => __('категория оценки'),
                'payment.is_pre_visit_booking_payment' => __('оплата записи до визита'),
                'survey.available' => __('доступность теста'),
                'survey.progress_available' => __('сравнимая динамика теста'),
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
            'requested' => __('ожидает подтверждения'),
            'pending_review' => __('на рассмотрении'),
            'confirmed' => __('подтверждена'),
            'completed' => __('завершена'),
            'cancelled' => __('отменена'),
            'ru' => __('русский'),
            'en' => __('английский'),
            NpsBand::Positive->value => __('положительная'),
            NpsBand::Internal->value => __('внутренняя'),
            'true' => __('да'),
            'false' => __('нет'),
            'contacts' => __('контакты'),
            'profile' => __('профиль'),
            'service' => __('услуга'),
            'goals' => __('цели'),
            default => (string) $item,
        })->implode(', ');
    }

    private static function recipientSummary(mixed $strategy): string
    {
        $type = is_array($strategy) ? ($strategy['type'] ?? null) : null;

        return match ($type) {
            'client' => __('Клиент записи'),
            'assigned_specialist' => __('Назначенный специалист'),
            'members' => __('Выбранные сотрудники'),
            'roles' => __('Сотрудники по роли'),
            default => __('Не указано'),
        };
    }

    private static function channelSummary(mixed $channels): string
    {
        return collect(is_array($channels) ? $channels : [])->map(static fn (mixed $channel): string => match ((string) $channel) {
            'telegram' => 'Telegram',
            default => __('Другой способ связи'),
        })->implode(' → ');
    }
}
