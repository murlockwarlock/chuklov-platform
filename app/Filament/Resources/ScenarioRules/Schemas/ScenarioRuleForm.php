<?php

namespace App\Filament\Resources\ScenarioRules\Schemas;

use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Resources\NotificationTemplates\Schemas\NotificationTemplateForm;
use App\Filament\Support\MessageComposer;
use App\Filament\Support\RichTextPresentation;
use App\Models\User;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Application\CreateNotificationTemplate;
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
                Section::make('Автоматическое сообщение')
                    ->schema([
                        Hidden::make('rule_key'),
                        Hidden::make('purpose')->default(ScenarioRulePurpose::Service->value),
                        Hidden::make('channel_priority')->default(['telegram']),
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
                    ->description('Авто-сообщение определяет, когда, кому и куда отправлять. Текст хранится отдельно и версионируется.')
                    ->schema([
                        Select::make('template_version_id')
                            ->label('Сообщение')
                            ->options(fn (Get $get): array => self::templateOptions((string) ($get('purpose') ?: ScenarioRulePurpose::Service->value)))
                            ->searchable()
                            ->placeholder('Нет опубликованных сообщений')
                            ->required()
                            ->helperText('Уже отправленные сообщения сохраняют свой текст.'),
                        Placeholder::make('template_preview')
                            ->label('Текст сообщения')
                            ->content(fn (Get $get): string => self::selectedTemplatePreview($get))
                            ->columnSpanFull(),
                        Placeholder::make('template_empty')
                            ->label('Готовые сообщения')
                            ->content('Нет готовых шаблонов для этого типа сообщения.')
                            ->visible(fn (Get $get): bool => self::templateOptions((string) ($get('purpose') ?: ScenarioRulePurpose::Service->value)) === []),
                        Actions::make([
                            Action::make('createMessage')
                                ->label('Создать текст сообщения')
                                ->icon('heroicon-o-plus')
                                ->slideOver()
                                ->modalHeading('Создать текст сообщения')
                                ->modalSubmitActionLabel('Сохранить текст')
                                ->schema(self::templateComposerSchema())
                                ->fillForm(fn (?ScenarioRule $record): array => self::newTemplateFormData($record))
                                ->action(function (array $data, Set $set): void {
                                    $actor = auth()->user();
                                    abort_unless($actor instanceof User, 403);
                                    $data['variables'] = self::templateVariables($data);
                                    $template = app(CreateNotificationTemplate::class)->handle($actor, $data);
                                    $set('template_version_id', $template->latestVersion()->firstOrFail()->getKey(), shouldCallUpdatedHooks: true);
                                    Notification::make()->title('Текст сообщения создан')->success()->send();
                                }),
                            Action::make('editMessage')
                                ->label('Изменить текст сообщения')
                                ->icon('heroicon-o-pencil-square')
                                ->visible(fn (Get $get): bool => filled($get('template_version_id')))
                                ->slideOver()
                                ->modalHeading('Изменить текст сообщения')
                                ->modalSubmitActionLabel('Сохранить новую версию')
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
                                    Notification::make()->title('Новая версия текста сохранена')->success()->send();
                                }),
                        ])->key('template_actions')->columnSpanFull(),
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
            ScenarioEventType::CompanionRequestedSpecialist->value => 'Когда клиент просит специалиста',
            ScenarioEventType::CompanionFallbackFailed->value => 'Когда AI не смог ответить',
            ScenarioEventType::BroadcastDeliveryFailed->value => 'При сбое операционной рассылки',
            ScenarioEventType::ClientFeedbackSubmitted->value => 'После обратной связи клиента',
            ScenarioEventType::PayoutRequested->value => 'При запросе выплаты партнёра',
            ScenarioEventType::PayoutStatusChanged->value => 'При изменении статуса выплаты',
            ScenarioEventType::HomeVisitChanged->value => 'При изменении выездного визита',
            ScenarioEventType::AiEvaluationFailed->value => 'При сбое проверки AI',
            ScenarioEventType::ReferralLinkVisited->value => 'При переходе по реферальной ссылке',
            ScenarioEventType::PaymentProviderEventPrepared->value => 'Событие платёжного провайдера (подготовлено)',
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
                        .' · '.Str::limit(RichTextPresentation::text($version->body), 80)
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

    private static function selectedTemplatePreview(Get $get): string
    {
        $version = self::templateVersion((int) $get('template_version_id'));

        return $version === null
            ? 'Выберите опубликованный текст или создайте новый.'
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
            Hidden::make('locale')->default('ru'),
            Hidden::make('purpose')->default(ScenarioRulePurpose::Service->value),
            TextInput::make('name')
                ->label('Название текста')
                ->required()
                ->maxLength(160),
            Toggle::make('is_active')
                ->label('Текст включён')
                ->default(true)
                ->required(),
            TextInput::make('subject')
                ->label('Тема')
                ->maxLength(255)
                ->helperText('Необязательно для Telegram.'),
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
                'body' => 'Текст содержит неподдерживаемые данные. Используйте список доступных данных.',
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
