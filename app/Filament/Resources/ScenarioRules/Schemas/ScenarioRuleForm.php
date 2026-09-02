<?php

namespace App\Filament\Resources\ScenarioRules\Schemas;

use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class ScenarioRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('rule_key'),
                Hidden::make('purpose')->default(ScenarioRulePurpose::Service->value),
                Hidden::make('channel_priority')->default(['telegram']),

                Section::make('Автоматическое сообщение')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->placeholder('Например, сообщение после визита')
                            ->required()
                            ->maxLength(160),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),

                Section::make('1. Когда?')
                    ->description('Выберите, когда нужно отправить сообщение.')
                    ->schema([
                        Select::make('trigger_event')
                            ->label('Когда')
                            ->options(self::eventOptions())
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('delay_value')
                            ->label('Через сколько')
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(PHP_INT_MAX)
                            ->default(0),
                        Select::make('delay_unit')
                            ->label('Единица времени')
                            ->options(self::delayUnitOptions())
                            ->required()
                            ->default(ScenarioDelayUnit::Minutes->value),
                        Placeholder::make('appointment_reminders')
                            ->label('Перед визитом')
                            ->content('1 день, 2 часа и 30 минут до визита настраиваются отдельно: «Настройки расписания» → «Напоминания о записи».')
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('openReminderSettings')
                                ->label('Настроить напоминания о записи')
                                ->icon('heroicon-o-clock')
                                ->url(fn (): string => SchedulingConfiguration::getUrl()),
                        ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('2. Кому?')
                    ->description('Выберите человека, которому будет отправлено сообщение.')
                    ->schema([
                        Select::make('recipient_strategy.type')
                            ->label('Получатель')
                            ->options([
                                'client' => 'Клиент записи',
                                'assigned_specialist' => 'Назначенный специалист',
                                'members' => 'Выбранные сотрудники',
                                'roles' => 'Сотрудники по роли',
                            ])
                            ->required()
                            ->default('client')
                            ->live(),
                        Select::make('recipient_strategy.user_ids')
                            ->label('Сотрудники')
                            ->options(fn (): array => OrganizationMembership::query()
                                ->where('organization_id', app(OrganizationContext::class)->id())
                                ->active()
                                ->with('user')
                                ->orderBy('user_id')
                                ->get()
                                ->mapWithKeys(fn (OrganizationMembership $membership): array => [
                                    $membership->user_id => $membership->user?->name.' ('.$membership->user?->email.')',
                                ])
                                ->all())
                            ->multiple()
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('recipient_strategy.type') === 'members')
                            ->visible(fn (Get $get): bool => $get('recipient_strategy.type') === 'members')
                            ->columnSpanFull(),
                        Select::make('recipient_strategy.roles')
                            ->label('Роли сотрудников')
                            ->options([
                                OrganizationRole::Owner->value => 'Владелец',
                                OrganizationRole::Administrator->value => 'Администратор',
                                OrganizationRole::Staff->value => 'Сотрудник',
                            ])
                            ->multiple()
                            ->required(fn (Get $get): bool => $get('recipient_strategy.type') === 'roles')
                            ->visible(fn (Get $get): bool => $get('recipient_strategy.type') === 'roles')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('3. Что отправить?')
                    ->description('Выберите опубликованное сообщение. Если подходящего текста нет, его можно создать отдельным действием.')
                    ->schema([
                        Select::make('template_version_id')
                            ->label('Сообщение')
                            ->options(fn (Get $get): array => self::templateOptions((string) ($get('purpose') ?: ScenarioRulePurpose::Service->value)))
                            ->searchable()
                            ->placeholder('Нет опубликованных сообщений')
                            ->required()
                            ->helperText('Уже отправленные сообщения сохраняют свой текст.'),
                        Placeholder::make('template_empty')
                            ->label('Готовые сообщения')
                            ->content('Нет готовых шаблонов для этого типа сообщения.')
                            ->visible(fn (Get $get): bool => self::templateOptions((string) ($get('purpose') ?: ScenarioRulePurpose::Service->value)) === []),
                        Actions::make([
                            Action::make('createMessage')
                                ->label('Создать сообщение')
                                ->icon('heroicon-o-plus')
                                ->url(fn (): string => NotificationTemplateResource::getUrl('create')),
                        ]),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('4. Включить?')
                    ->schema([
                        Toggle::make('is_enabled')
                            ->label('Включить авто-сообщение')
                            ->helperText('Сообщения начнут отправляться только после включения этой настройки.')
                            ->required()
                            ->default(false),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),

                Section::make('Дополнительные настройки')
                    ->description('Эти параметры обычно не меняются.')
                    ->schema([
                        TextInput::make('max_occurrences')
                            ->label('Сколько раз отправить')
                            ->integer()
                            ->required()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(100)
                            ->live(),
                        TextInput::make('repeat_interval_value')
                            ->label('Пауза между повторами')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(PHP_INT_MAX)
                            ->visible(fn (Get $get): bool => (int) $get('max_occurrences') > 1)
                            ->required(fn (Get $get): bool => (int) $get('max_occurrences') > 1),
                        Select::make('repeat_interval_unit')
                            ->label('Единица паузы')
                            ->options(self::delayUnitOptions())
                            ->visible(fn (Get $get): bool => (int) $get('max_occurrences') > 1)
                            ->required(fn (Get $get): bool => (int) $get('max_occurrences') > 1),
                        Repeater::make('conditions')
                            ->label('Дополнительные условия')
                            ->schema([
                                Select::make('type')
                                    ->label('Что проверить')
                                    ->options(self::conditionOptions())
                                    ->required()
                                    ->live(),
                                Select::make('operator')
                                    ->label('Проверка')
                                    ->options([
                                        ScenarioConditionOperator::Equals->value => 'Равно',
                                        ScenarioConditionOperator::NotEquals->value => 'Не равно',
                                        ScenarioConditionOperator::In->value => 'Одно из',
                                        ScenarioConditionOperator::Exists->value => 'Заполнено',
                                    ])
                                    ->required()
                                    ->live(),
                                Select::make('value')
                                    ->label(fn (Get $get): string => self::conditionValueLabel($get, false))
                                    ->options(fn (Get $get): array => self::conditionValues($get('type')))
                                    ->searchable()
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::conditionValueIsSelect($get))
                                    ->required(fn (Get $get): bool => self::conditionValueIsSelect($get)
                                        && in_array($get('operator'), [
                                            ScenarioConditionOperator::Equals->value,
                                            ScenarioConditionOperator::NotEquals->value,
                                        ], true)),
                                Select::make('value')
                                    ->label(fn (Get $get): string => self::conditionValueLabel($get, true))
                                    ->options(fn (Get $get): array => self::conditionValues($get('type')))
                                    ->multiple()
                                    ->searchable()
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::conditionValues($get('type')) !== []
                                        && $get('operator') === ScenarioConditionOperator::In->value)
                                    ->required(fn (Get $get): bool => self::conditionValues($get('type')) !== []
                                        && $get('operator') === ScenarioConditionOperator::In->value),
                                TagsInput::make('value')
                                    ->label(fn (Get $get): string => self::conditionValueLabel($get, true))
                                    ->visible(fn (Get $get): bool => $get('operator') === ScenarioConditionOperator::In->value
                                        && self::conditionValues($get('type')) === [])
                                    ->required(fn (Get $get): bool => $get('operator') === ScenarioConditionOperator::In->value
                                        && self::conditionValues($get('type')) === [])
                                    ->live()
                                    ->nestedRecursiveRules(['string', 'max:120']),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel('Добавить условие')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }

    /** @return array<string, string> */
    private static function eventOptions(): array
    {
        return [
            ScenarioEventType::BookingCreated->value => 'После новой записи',
            ScenarioEventType::BookingConfirmed->value => 'После подтверждения',
            ScenarioEventType::BookingRescheduled->value => 'После переноса',
            ScenarioEventType::BookingCancelled->value => 'После отмены',
            ScenarioEventType::BookingCompleted->value => 'После визита',
            ScenarioEventType::OnboardingStarted->value => 'После начала оформления',
            ScenarioEventType::FinancialObligationCreated->value => 'После появления задолженности',
            ScenarioEventType::SurveyCompleted->value => 'После завершения теста',
            ScenarioEventType::TestStagnationDetected->value => 'Если показатели не снижаются',
            ScenarioEventType::B2bLeadSubmitted->value => 'После B2B-запроса',
            ScenarioEventType::B2bSalesCallReady->value => 'Когда B2B-разговор готов',
        ];
    }

    /** @return array<string, string> */
    private static function delayUnitOptions(): array
    {
        return [
            ScenarioDelayUnit::Minutes->value => 'минут',
            ScenarioDelayUnit::Hours->value => 'часов',
            ScenarioDelayUnit::Days->value => 'дней',
        ];
    }

    /** @return array<string, string> */
    private static function conditionOptions(): array
    {
        return [
            'booking.status' => 'Статус записи',
            'booking.has_qualifying_next_booking' => 'Есть следующая запись',
            'client.language' => 'Язык клиента',
            'client.marketing_consent' => 'Согласие на сообщения',
            'onboarding.completed' => 'Оформление завершено',
            'onboarding.stage' => 'Этап оформления',
            'finance.has_outstanding_debt' => 'Есть задолженность',
        ];
    }

    /** @return array<string, string> */
    private static function conditionValues(mixed $type): array
    {
        return match ($type) {
            'booking.status' => [
                'requested' => 'Ожидает подтверждения',
                'pending_review' => 'На рассмотрении',
                'confirmed' => 'Подтверждена',
                'rejected' => 'Отклонена',
                'cancelled' => 'Отменена',
                'completed' => 'Завершена',
                'no_show' => 'Не состоялась',
            ],
            'client.language' => [
                'ru' => 'Русский',
                'en' => 'Английский',
            ],
            'booking.has_qualifying_next_booking',
            'onboarding.completed',
            'client.marketing_consent',
            'finance.has_outstanding_debt' => [
                'true' => 'Да',
                'false' => 'Нет',
            ],
            'onboarding.stage' => [
                'contacts' => 'Контакты',
                'profile' => 'Профиль',
                'service' => 'Услуга',
                'goals' => 'Цели',
            ],
            default => [],
        };
    }

    private static function conditionValueIsSelect(Get $get): bool
    {
        return $get('operator') !== ScenarioConditionOperator::In->value
            && $get('operator') !== ScenarioConditionOperator::Exists->value
            && self::conditionValues($get('type')) !== [];
    }

    private static function conditionValueLabel(Get $get, bool $multiple): string
    {
        return match ($get('type')) {
            'booking.status' => 'Статус записи',
            'client.language' => 'Язык',
            'booking.has_qualifying_next_booking',
            'onboarding.completed',
            'client.marketing_consent',
            'finance.has_outstanding_debt' => 'Ответ',
            'onboarding.stage' => 'Этап оформления',
            default => $multiple ? 'Значения условия' : 'Значение условия',
        };
    }

    /** @return array<string, string> */
    private static function templateOptions(string $purpose): array
    {
        $organizationId = app(OrganizationContext::class)->id();

        return NotificationTemplateVersion::query()
            ->where('organization_id', $organizationId)
            ->where('status', NotificationTemplateStatus::Published->value)
            ->whereHas('template', fn (Builder $query): Builder => $query
                ->where('organization_id', $organizationId)
                ->where('purpose', $purpose)
                ->where('is_active', true))
            ->with('template')
            ->latest('id')
            ->get()
            ->mapWithKeys(function (NotificationTemplateVersion $version): array {
                $template = $version->template;

                return [
                    $version->getKey() => ($template?->name ?: 'Сообщение')
                        .' · '.Str::limit(trim((string) $version->body), 80)
                        .' · '.self::localeLabel($template?->locale),
                ];
            })
            ->all();
    }

    private static function localeLabel(?string $locale): string
    {
        return match ($locale) {
            'ru' => 'Русский',
            'en' => 'Английский',
            default => 'Другой язык',
        };
    }
}
