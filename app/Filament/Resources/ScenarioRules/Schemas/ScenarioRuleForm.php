<?php

namespace App\Filament\Resources\ScenarioRules\Schemas;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class ScenarioRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название правила')
                            ->required()
                            ->maxLength(160),
                        Toggle::make('is_enabled')
                            ->label('Активно')
                            ->helperText('Пока правило выключено, новые отправки по нему не создаются. Уже запланированные отправки не будут выполнены, если правило останется выключенным к моменту отправки.')
                            ->required()
                            ->default(false),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('1. Когда отправлять')
                    ->description('Укажите событие в CRM и задержку перед отправкой сообщения.')
                    ->schema([
                        Select::make('trigger_event')
                            ->label('Событие')
                            ->options([
                                ScenarioEventType::BookingCreated->value => 'Новая заявка на запись',
                                ScenarioEventType::BookingConfirmed->value => 'Запись подтверждена',
                                ScenarioEventType::BookingRescheduled->value => 'Запись перенесена',
                                ScenarioEventType::BookingCancelled->value => 'Запись отменена',
                                ScenarioEventType::BookingCompleted->value => 'Клиент завершил визит',
                                ScenarioEventType::OnboardingStarted->value => 'Клиент начал оформление',
                                ScenarioEventType::FinancialObligationCreated->value => 'Появилась задолженность за визит',
                                ScenarioEventType::SurveyCompleted->value => 'Клиент завершил тест',
                                ScenarioEventType::TestStagnationDetected->value => 'В повторном тесте нет снижения показателей',
                                ScenarioEventType::B2bLeadSubmitted->value => 'Отправлен B2B-запрос',
                                ScenarioEventType::B2bSalesCallReady->value => 'B2B-разговор готов к подключению',
                            ])
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('delay_value')
                            ->label('Задержка перед отправкой')
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(PHP_INT_MAX)
                            ->default(0),
                        Select::make('delay_unit')
                            ->label('Единица времени')
                            ->options([
                                ScenarioDelayUnit::Minutes->value => 'минут',
                                ScenarioDelayUnit::Hours->value => 'часов',
                                ScenarioDelayUnit::Days->value => 'дней',
                            ])
                            ->required()
                            ->default(ScenarioDelayUnit::Minutes->value),
                        TextInput::make('max_occurrences')
                            ->label('Максимум сообщений')
                            ->integer()
                            ->required()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(100)
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('repeat_interval_value')
                            ->label('Интервал между повторами')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(PHP_INT_MAX)
                            ->visible(fn (Get $get): bool => (int) $get('max_occurrences') > 1)
                            ->required(fn (Get $get): bool => (int) $get('max_occurrences') > 1),
                        Select::make('repeat_interval_unit')
                            ->label('Единица интервала')
                            ->options([
                                ScenarioDelayUnit::Minutes->value => 'минут',
                                ScenarioDelayUnit::Hours->value => 'часов',
                                ScenarioDelayUnit::Days->value => 'дней',
                            ])
                            ->visible(fn (Get $get): bool => (int) $get('max_occurrences') > 1)
                            ->required(fn (Get $get): bool => (int) $get('max_occurrences') > 1),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('2. При каких условиях')
                    ->description('Дополнительные фильтры для отправки сообщения.')
                    ->schema([
                        Repeater::make('conditions')
                            ->label('Условия отправки')
                            ->schema([
                                Select::make('type')
                                    ->label('Что проверить')
                                    ->options([
                                        'booking.status' => 'Статус записи',
                                        'booking.has_qualifying_next_booking' => 'Есть подходящая следующая запись',
                                        'client.language' => 'Язык клиента',
                                        'client.marketing_consent' => 'Согласие на маркетинговые сообщения',
                                        'onboarding.completed' => 'Оформление завершено',
                                        'onboarding.stage' => 'Этап оформления',
                                        'finance.has_outstanding_debt' => 'Есть непогашенная задолженность',
                                    ])
                                    ->required(),
                                Select::make('operator')
                                    ->label('Проверка')
                                    ->options([
                                        ScenarioConditionOperator::Equals->value => 'Равно',
                                        ScenarioConditionOperator::NotEquals->value => 'Не равно',
                                        ScenarioConditionOperator::In->value => 'Одно из',
                                        ScenarioConditionOperator::Exists->value => 'Заполнено',
                                    ])
                                    ->required(),
                                Select::make('value')
                                    ->label('Значение')
                                    ->options(fn (Get $get): array => self::conditionValues($get('type')))
                                    ->searchable()
                                    ->visible(fn (Get $get): bool => ! in_array($get('operator'), [
                                        ScenarioConditionOperator::In->value,
                                        ScenarioConditionOperator::Exists->value,
                                    ], true))
                                    ->required(fn (Get $get): bool => in_array($get('operator'), [
                                        ScenarioConditionOperator::Equals->value,
                                        ScenarioConditionOperator::NotEquals->value,
                                    ], true)),
                                TagsInput::make('value')
                                    ->label('Значения')
                                    ->visible(fn (Get $get): bool => $get('operator') === ScenarioConditionOperator::In->value)
                                    ->required(fn (Get $get): bool => $get('operator') === ScenarioConditionOperator::In->value)
                                    ->nestedRecursiveRules(['string', 'max:120']),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel('Добавить условие')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('3. Что отправлять')
                    ->description('Выберите категорию и опубликованный шаблон сообщения.')
                    ->schema([
                        Select::make('purpose')
                            ->label('Назначение сообщения')
                            ->options([
                                ScenarioRulePurpose::Service->value => 'Сервисное сообщение',
                                ScenarioRulePurpose::Transactional->value => 'Системное сообщение',
                            ])
                            ->helperText('Категория сообщения. Сама по себе не определяет получателя или канал связи.')
                            ->live()
                            ->required(),
                        Select::make('template_version_id')
                            ->label('Шаблон сообщения')
                            ->options(fn (Get $get): array => NotificationTemplateVersion::query()
                                ->where('organization_id', app(OrganizationContext::class)->id())
                                ->where('status', NotificationTemplateStatus::Published->value)
                                ->whereHas('template', fn ($query) => $query
                                    ->where('organization_id', app(OrganizationContext::class)->id())
                                    ->where('purpose', (string) $get('purpose'))
                                    ->where('is_active', true))
                                ->with('template')
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(function (NotificationTemplateVersion $version): array {
                                    $template = $version->template;

                                    return [
                                        $version->getKey() => ($template?->name ?: 'Сообщение').' — '.self::localeLabel($template?->locale).' (v'.$version->version.')',
                                    ];
                                })
                                ->all())
                            ->searchable()
                            ->required()
                            ->helperText('Изменение текста создаёт новую версию. Существующие правила продолжают использовать выбранную ранее версию, пока вы явно не выберете новую.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('4. Кому и как отправлять')
                    ->description('Определите получателя и способ связи.')
                    ->schema([
                        Select::make('recipient_strategy.type')
                            ->label('Кому отправлять')
                            ->options([
                                'client' => 'Клиенту записи',
                                'members' => 'Выбранным сотрудникам',
                                'roles' => 'Сотрудникам по роли',
                                'assigned_specialist' => 'Назначенному специалисту',
                            ])
                            ->required()
                            ->default('client')
                            ->live(),
                        Select::make('channel_priority')
                            ->label('Способ связи')
                            ->options([
                                'telegram' => 'Telegram',
                            ])
                            ->multiple()
                            ->required()
                            ->default(['telegram']),
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
                            ->label('Роли')
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
            ]);
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
            'booking.has_qualifying_next_booking', 'onboarding.completed', 'client.marketing_consent' => [
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

    private static function localeLabel(?string $locale): string
    {
        return match ($locale) {
            'ru' => 'Русский',
            'en' => 'Английский',
            default => 'Другой язык',
        };
    }
}
