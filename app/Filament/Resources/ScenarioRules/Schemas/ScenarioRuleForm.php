<?php

namespace App\Filament\Resources\ScenarioRules\Schemas;

use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Resources\NotificationTemplates\Schemas\NotificationTemplateForm;
use App\Filament\Support\MessageComposer;
use App\Filament\Support\RichTextPresentation;
use App\Models\User;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Feedback\Domain\Enums\NpsBand;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Application\CreateNotificationTemplate;
use App\Modules\Scenarios\Application\NotificationTemplateSnapshotHasher;
use App\Modules\Scenarios\Application\UpdateNotificationTemplate;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ScenarioRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Автоматическое сообщение'))
                    ->schema([
                        Hidden::make('rule_key'),
                        Hidden::make('purpose')->default(ScenarioRulePurpose::Service->value),
                        Hidden::make('channel_priority')->default(['telegram']),
                        TextInput::make('name')
                            ->label(__('Название'))
                            ->placeholder(__('Например, сообщение после визита'))
                            ->required()
                            ->maxLength(160),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),

                Section::make(__('1. Когда?'))
                    ->description(__('Выберите, когда нужно отправить сообщение.'))
                    ->schema([
                        Select::make('trigger_event')
                            ->label(__('Когда'))
                            ->options(self::eventOptions())
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('delay_value')
                            ->label(__('Через сколько'))
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(PHP_INT_MAX)
                            ->default(0),
                        Select::make('delay_unit')
                            ->label(__('Единица времени'))
                            ->options(self::delayUnitOptions())
                            ->required()
                            ->default(ScenarioDelayUnit::Minutes->value),
                        Placeholder::make('appointment_reminders')
                            ->label(__('Перед визитом'))
                            ->content(__('1 день, 2 часа и 30 минут до визита настраиваются отдельно: «Настройки расписания» → «Напоминания о записи».'))
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('openReminderSettings')
                                ->label(__('Настроить напоминания о записи'))
                                ->icon('heroicon-o-clock')
                                ->url(fn (): string => SchedulingConfiguration::getUrl()),
                        ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('2. Кому?'))
                    ->description(__('Выберите человека, которому будет отправлено сообщение.'))
                    ->schema([
                        Select::make('recipient_strategy.type')
                            ->label(__('Получатель'))
                            ->options([
                                'client' => __('Клиент записи'),
                                'assigned_specialist' => __('Назначенный специалист'),
                                'members' => __('Выбранные сотрудники'),
                                'roles' => __('Сотрудники по роли'),
                            ])
                            ->required()
                            ->default('client')
                            ->live(),
                        Select::make('recipient_strategy.user_ids')
                            ->label(__('Сотрудники'))
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
                            ->label(__('Роли сотрудников'))
                            ->options([
                                OrganizationRole::Owner->value => __('Владелец'),
                                OrganizationRole::Administrator->value => __('Администратор'),
                                OrganizationRole::Staff->value => __('Сотрудник'),
                            ])
                            ->multiple()
                            ->required(fn (Get $get): bool => $get('recipient_strategy.type') === 'roles')
                            ->visible(fn (Get $get): bool => $get('recipient_strategy.type') === 'roles')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('3. Что отправить?'))
                    ->description(__('Авто-сообщение определяет, когда, кому и куда отправлять. Текст хранится отдельно и версионируется.'))
                    ->schema([
                        Select::make('template_version_id')
                            ->label(__('Сообщение'))
                            ->options(fn (Get $get): array => self::templateOptions((string) ($get('purpose') ?: ScenarioRulePurpose::Service->value)))
                            ->searchable()
                            ->placeholder(__('Нет опубликованных сообщений'))
                            ->required()
                            ->helperText(__('Уже отправленные сообщения сохраняют свой текст.')),
                        Placeholder::make('template_preview')
                            ->label(__('Текст сообщения'))
                            ->content(fn (Get $get): string => self::selectedTemplatePreview($get))
                            ->columnSpanFull(),
                        Placeholder::make('template_empty')
                            ->label(__('Готовые сообщения'))
                            ->content(__('Нет готовых шаблонов для этого типа сообщения.'))
                            ->visible(fn (Get $get): bool => self::templateOptions((string) ($get('purpose') ?: ScenarioRulePurpose::Service->value)) === []),
                        Actions::make([
                            Action::make('createMessage')
                                ->label(__('Создать текст сообщения'))
                                ->icon('heroicon-o-plus')
                                ->slideOver()
                                ->modalHeading(__('Создать текст сообщения'))
                                ->modalSubmitActionLabel(__('Сохранить текст'))
                                ->schema(self::templateComposerSchema())
                                ->fillForm(fn (?ScenarioRule $record): array => self::newTemplateFormData($record))
                                ->action(function (array $data, Set $set): void {
                                    $actor = auth()->user();
                                    abort_unless($actor instanceof User, 403);
                                    $data['variables'] = self::templateVariables($data);
                                    $template = app(CreateNotificationTemplate::class)->handle($actor, $data);
                                    $set('template_version_id', $template->latestVersion()->firstOrFail()->getKey(), shouldCallUpdatedHooks: true);
                                    Notification::make()->title(__('Текст сообщения создан'))->success()->send();
                                }),
                            Action::make('editMessage')
                                ->label(__('Изменить текст сообщения'))
                                ->icon('heroicon-o-pencil-square')
                                ->visible(fn (Get $get): bool => filled($get('template_version_id')))
                                ->slideOver()
                                ->modalHeading(__('Изменить текст сообщения'))
                                ->modalSubmitActionLabel(__('Сохранить новую версию'))
                                ->schema(self::templateComposerSchema())
                                ->fillForm(fn (?ScenarioRule $record, Get $get): array => self::existingTemplateFormData($record, (int) $get('template_version_id')))
                                ->action(function (array $data, Set $set): void {
                                    $actor = auth()->user();
                                    abort_unless($actor instanceof User, 403);
                                    $version = self::templateVersion((int) ($data['template_version_id'] ?? 0));
                                    $template = $version?->template;
                                    abort_unless($template !== null, 404);
                                    $data['template_key'] = $template->template_key;
                                    $data['locale'] = $template->locale;
                                    $data['variables'] = self::templateVariables($data);
                                    $updated = app(UpdateNotificationTemplate::class)->handle($actor, $template, $data);
                                    $set('template_version_id', $updated->latestVersion()->firstOrFail()->getKey(), shouldCallUpdatedHooks: true);
                                    Notification::make()->title(__('Новая версия текста сохранена'))->success()->send();
                                }),
                        ])->key('template_actions')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('4. Включить?'))
                    ->schema([
                        Toggle::make('is_enabled')
                            ->label(__('Включить авто-сообщение'))
                            ->helperText(__('Сообщения начнут отправляться только после включения этой настройки.'))
                            ->required()
                            ->default(false),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),

                Section::make(__('Дополнительные настройки'))
                    ->description(__('Эти параметры обычно не меняются.'))
                    ->schema([
                        TextInput::make('max_occurrences')
                            ->label(__('Сколько раз отправить'))
                            ->integer()
                            ->required()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(100)
                            ->live(),
                        TextInput::make('repeat_interval_value')
                            ->label(__('Пауза между повторами'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(PHP_INT_MAX)
                            ->visible(fn (Get $get): bool => (int) $get('max_occurrences') > 1)
                            ->required(fn (Get $get): bool => (int) $get('max_occurrences') > 1),
                        Select::make('repeat_interval_unit')
                            ->label(__('Единица паузы'))
                            ->options(self::delayUnitOptions())
                            ->visible(fn (Get $get): bool => (int) $get('max_occurrences') > 1)
                            ->required(fn (Get $get): bool => (int) $get('max_occurrences') > 1),
                        Repeater::make('conditions')
                            ->label(__('Дополнительные условия'))
                            ->schema([
                                Select::make('type')
                                    ->label(__('Что проверить'))
                                    ->options(self::conditionOptions())
                                    ->required()
                                    ->live(),
                                Select::make('operator')
                                    ->label(__('Проверка'))
                                    ->options([
                                        ScenarioConditionOperator::Equals->value => __('Равно'),
                                        ScenarioConditionOperator::NotEquals->value => __('Не равно'),
                                        ScenarioConditionOperator::In->value => __('Одно из'),
                                        ScenarioConditionOperator::Exists->value => __('Заполнено'),
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
                            ->addActionLabel(__('Добавить условие'))
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
            ScenarioEventType::BookingCreated->value => __('После новой записи'),
            ScenarioEventType::BookingConfirmed->value => __('После подтверждения'),
            ScenarioEventType::BookingRescheduled->value => __('После переноса'),
            ScenarioEventType::BookingCancelled->value => __('После отмены'),
            ScenarioEventType::BookingCompleted->value => __('После визита'),
            ScenarioEventType::OnboardingStarted->value => __('После начала оформления'),
            ScenarioEventType::FinancialObligationCreated->value => __('После появления задолженности'),
            ScenarioEventType::FinancialDebtReminderRequested->value => __('При отправке напоминания о задолженности'),
            ScenarioEventType::SurveyCompleted->value => __('После завершения теста'),
            ScenarioEventType::TestStagnationDetected->value => __('Если показатели не снижаются'),
            ScenarioEventType::B2bLeadSubmitted->value => __('После B2B-запроса'),
            ScenarioEventType::B2bSalesCallReady->value => __('Когда B2B-разговор готов'),
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
            ScenarioEventType::ReferralRewardEarned->value => __('При начислении реферального бонуса'),
        ];
    }

    /** @return array<string, string> */
    private static function delayUnitOptions(): array
    {
        return [
            ScenarioDelayUnit::Minutes->value => __('минут'),
            ScenarioDelayUnit::Hours->value => __('часов'),
            ScenarioDelayUnit::Days->value => __('дней'),
        ];
    }

    /** @return array<string, string> */
    private static function conditionOptions(): array
    {
        return [
            'booking.status' => __('Статус записи'),
            'booking.has_qualifying_next_booking' => __('Есть следующая запись'),
            'client.language' => __('Язык клиента'),
            'client.marketing_consent' => __('Согласие на сообщения'),
            'onboarding.completed' => __('Оформление завершено'),
            'onboarding.stage' => __('Этап оформления'),
            'finance.has_outstanding_debt' => __('Есть задолженность'),
            'feedback.band' => __('Категория оценки'),
            'payment.is_pre_visit_booking_payment' => __('Оплата записи до визита'),
            'survey.available' => __('Доступен тест'),
            'survey.progress_available' => __('Есть сравнимая динамика теста'),
        ];
    }

    /** @return array<string, string> */
    private static function conditionValues(mixed $type): array
    {
        return match ($type) {
            'booking.status' => [
                'requested' => __('Ожидает подтверждения'),
                'pending_review' => __('На рассмотрении'),
                'confirmed' => __('Подтверждена'),
                'rejected' => __('Отклонена'),
                'cancelled' => __('Отменена'),
                'completed' => __('Завершена'),
                'no_show' => __('Не состоялась'),
            ],
            'client.language' => [
                'ru' => __('Русский'),
                'en' => __('Английский'),
            ],
            'booking.has_qualifying_next_booking',
            'onboarding.completed',
            'client.marketing_consent',
            'finance.has_outstanding_debt',
            'payment.is_pre_visit_booking_payment',
            'survey.available',
            'survey.progress_available' => [
                'true' => __('Да'),
                'false' => __('Нет'),
            ],
            'feedback.band' => collect(NpsBand::cases())->mapWithKeys(fn (NpsBand $band): array => [
                $band->value => match ($band) {
                    NpsBand::Positive => __('Положительная'),
                    NpsBand::Internal => __('Внутренняя'),
                },
            ])->all(),
            'onboarding.stage' => [
                'contacts' => __('Контакты'),
                'profile' => __('Профиль'),
                'service' => __('Услуга'),
                'goals' => __('Цели'),
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
            'booking.status' => __('Статус записи'),
            'client.language' => __('Язык'),
            'booking.has_qualifying_next_booking',
            'onboarding.completed',
            'client.marketing_consent',
            'finance.has_outstanding_debt',
            'payment.is_pre_visit_booking_payment',
            'survey.available',
            'survey.progress_available' => __('Ответ'),
            'feedback.band' => __('Категория'),
            'onboarding.stage' => __('Этап оформления'),
            default => $multiple ? __('Значения условия') : __('Значение условия'),
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
                    $version->getKey() => ($template?->name ?: __('Сообщение'))
                        .' · '.Str::limit(RichTextPresentation::text($version->body), 80)
                        .' · '.self::localeLabel($template?->locale),
                ];
            })
            ->all();
    }

    private static function localeLabel(?string $locale): string
    {
        return match ($locale) {
            'ru' => __('Русский'),
            'en' => __('Английский'),
            default => __('Другой язык'),
        };
    }

    private static function selectedTemplatePreview(Get $get): string
    {
        $version = self::templateVersion((int) $get('template_version_id'));

        return $version === null
            ? __('Выберите опубликованный текст или создайте новый.')
            : Str::limit(RichTextPresentation::text($version->body), 500);
    }

    /** @return array<string, mixed> */
    private static function newTemplateFormData(?ScenarioRule $record): array
    {
        return [
            'purpose' => $record?->purpose->value ?? ScenarioRulePurpose::Service->value,
            'locale' => 'ru',
            'is_active' => true,
            'delivery_mode' => NotificationMessageMode::Text->value,
            'caption_position' => 'below',
        ];
    }

    /** @return array<string, mixed> */
    private static function existingTemplateFormData(?ScenarioRule $record, ?int $selectedVersionId = null): array
    {
        $version = self::templateVersion($selectedVersionId ?? (int) $record?->template_version_id)
            ?? $record?->templateVersion;
        $template = $version?->template;

        if ($version === null || $template === null) {
            return self::newTemplateFormData($record);
        }

        return [
            'template_version_id' => $version->getKey(),
            'expected_snapshot' => app(NotificationTemplateSnapshotHasher::class)->forTemplate($template, $template->latestVersion()->firstOrFail()),
            'name' => $template->name,
            'locale' => $template->locale,
            'purpose' => $template->purpose,
            'is_active' => $template->is_active,
            'subject' => $version->subject,
            'body' => $version->body,
            'delivery_mode' => ($version->delivery_mode ?? NotificationMessageMode::Text)->value,
            'caption_position' => $version->caption_position ?: 'below',
        ];
    }

    /** @return array<int, mixed> */
    private static function templateComposerSchema(): array
    {
        return [
            Hidden::make('template_version_id'),
            Hidden::make('expected_snapshot')->dehydrated()->nullable()->string(),
            Hidden::make('locale')->default('ru'),
            Hidden::make('purpose')->default(ScenarioRulePurpose::Service->value),
            TextInput::make('name')
                ->label(__('Название текста'))
                ->required()
                ->maxLength(160),
            Toggle::make('is_active')
                ->label(__('Текст включён'))
                ->default(true)
                ->required(),
            TextInput::make('subject')
                ->label(__('Тема'))
                ->maxLength(255)
                ->helperText(__('Необязательно для Telegram.')),
            ...MessageComposer::make(
                bodyField: 'body',
                deliveryModeField: 'delivery_mode',
                mediaField: 'media_image',
                mediaUrlField: 'media_url',
                variables: fn (Get $get): array => ScenarioTemplateVariableCatalog::labelsForPurpose($get('purpose')),
                preview: fn (Get $get, ?Model $record): NotificationMessage => NotificationTemplateForm::previewMessage($get, $record),
                bodyLabel: 'Текст сообщения',
                bodyHelper: 'Используйте форматирование, ссылки, эмодзи и доступные данные.',
                requireMedia: false,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function templateVariables(array $data): array
    {
        try {
            return ScenarioTemplateVariableCatalog::used(
                (string) ($data['body'] ?? ''),
                (string) ($data['subject'] ?? ''),
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'body' => __('Текст содержит неподдерживаемые данные. Используйте список доступных данных.'),
            ]);
        }
    }

    private static function templateVersion(int $id): ?NotificationTemplateVersion
    {
        if ($id < 1) {
            return null;
        }

        return NotificationTemplateVersion::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey($id)
            ->with('template')
            ->first();
    }
}
