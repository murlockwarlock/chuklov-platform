<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Filament\Support\ScheduleImpactPreview;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\B2B\Application\GetB2bSalesCallDuration;
use App\Modules\B2B\Application\GetB2bSalesCallReadiness;
use App\Modules\B2B\Application\GetB2bZoomConfiguration;
use App\Modules\B2B\Application\SaveB2bZoomConfiguration;
use App\Modules\Organizations\Application\ClearOrganizationSetting;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Scenarios\Application\EnsureAppointmentReminderDefaults;
use App\Modules\Scenarios\Application\UpdateAppointmentReminders;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Models\AppointmentReminder;
use App\Modules\Scheduling\Application\GetBookingCancellationCutoff;
use App\Modules\Scheduling\Application\GetBookingLeadTime;
use App\Modules\Scheduling\Application\GetHomeVisitOccupiedBuffer;
use App\Modules\Scheduling\Application\SetBookingCancellationCutoff;
use App\Modules\Scheduling\Application\SetBookingLeadTime;
use App\Modules\Scheduling\Application\SetOnlineConsultationService;
use App\Modules\Scheduling\Application\UpdateWorkingLocation;
use App\Modules\Scheduling\Domain\Enums\PatientNotificationRecipientPolicy;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Enums\ServicePaymentRequirement;
use App\Modules\Services\Domain\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class SchedulingConfiguration extends LocalizedPage
{
    protected static ?string $title = 'Настройки расписания';

    protected static ?string $navigationLabel = 'Настройки расписания';

    protected ?string $subheading = 'Здесь настраиваются общие параметры для всего расписания';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Записи';

    protected static ?int $navigationSort = 99;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    protected string $view = 'filament.pages.scheduling-configuration';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        try {
            return app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ManageScheduling,
            );
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $organization = app(OrganizationContext::class)->organization();
        if (! AppointmentReminder::query()->where('organization_id', $organization->getKey())->exists()) {
            app(EnsureAppointmentReminderDefaults::class)->handle($organization);
        }

        $zoom = app(GetB2bZoomConfiguration::class)->handle();

        $this->form->fill([
            'lead_time_minutes' => app(GetBookingLeadTime::class)->handle(),
            'cancellation_cutoff_minutes' => app(GetBookingCancellationCutoff::class)->handle(),
            'b2b_sales_call_duration_minutes' => app(GetB2bSalesCallDuration::class)->handle(),
            'online_consultation_service_id' => $organization->settings()
                ->where('setting_key', OrganizationSettingKey::OnlineConsultationServiceId->value)
                ->value('integer_value'),
            'default_timezone' => $organization->defaultTimezone(),
            'home_visit_occupied_buffer_minutes' => app(GetHomeVisitOccupiedBuffer::class)->handle(),
            'b2b_zoom_host_licensed' => (bool) $organization->settings()
                ->where('setting_key', OrganizationSettingKey::B2bZoomHostLicensed->value)
                ->value('boolean_value'),
            'office_location' => $organization->settings()
                ->where('setting_key', OrganizationSettingKey::OfficeLocation->value)
                ->value('string_value'),
            'patient_notification_recipients' => $organization->settings()
                ->where('setting_key', OrganizationSettingKey::PatientNotificationRecipients->value)
                ->value('string_value') ?? PatientNotificationRecipientPolicy::Both->value,
            'client_reminders' => $this->reminderRows('client'),
            'specialist_reminders' => $this->reminderRows('specialist'),
            'zoom_enabled' => $zoom['enabled'],
            'zoom_account_id' => $zoom['accountId'],
            'zoom_client_id' => $zoom['clientId'],
            'zoom_client_secret' => null,
            'zoom_host_user_id' => $zoom['hostUserId'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('home_visit_occupied_buffer_minutes')
                    ->label(__('Буфер выезда после консультации (минуты)'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(1440),
                TextInput::make('lead_time_minutes')
                    ->label(__('Минимальный срок до записи (минуты)'))
                    ->integer()
                    ->minValue(0)
                    ->helperText(__('За сколько минут до начала визита клиент может оформить запись.'))
                    ->required(),
                TextInput::make('cancellation_cutoff_minutes')
                    ->label(__('Срок отмены или переноса (минуты)'))
                    ->integer()
                    ->minValue(0)
                    ->helperText(__('За сколько минут до визита клиент может бесплатно отменить или перенести запись.'))
                    ->required(),
                Section::make(__('После результатов теста'))
                    ->description(__('Эта услуга предлагается клиенту после завершения теста.'))
                    ->schema([
                        Select::make('online_consultation_service_id')
                            ->label(__('Консультация после результатов теста'))
                            ->options(fn (): array => $this->onlineConsultationServices())
                            ->searchable()
                            ->nullable()
                            ->placeholder(__('Не настроено'))
                            ->helperText(__('Доступны только активные услуги с полной предоплатой и назначенным специалистом.')),
                    ])
                    ->columnSpanFull(),
                Select::make('default_timezone')
                    ->label(__('Часовой пояс организации'))
                    ->options(fn (Get $get): array => TimezoneOptions::options(
                        current: $get('default_timezone'),
                        organization: app(OrganizationContext::class)->organization()->defaultTimezone(),
                    ))
                    ->searchable()
                    ->required()
                    ->helperText(__('Используется для расписаний и уведомлений в CRM.')),
                TextInput::make('office_location')
                    ->label(__('Адрес приёма по умолчанию'))
                    ->helperText(__('Подставляется в новые записи в кабинете. Адрес уже созданных записей не изменится.'))
                    ->maxLength(500),
                Select::make('patient_notification_recipients')
                    ->label(__('Кому отправлять уведомления о записи'))
                    ->options(collect(PatientNotificationRecipientPolicy::cases())->mapWithKeys(fn (PatientNotificationRecipientPolicy $policy): array => [$policy->value => $policy->label()])->all())
                    ->required()
                    ->native(false)
                    ->helperText(__('Настройка применяется к уведомлениям о записи пациента и связанных с ней изменениях.')),
                Section::make(__('Напоминания о записи'))
                    ->description(__('Выберите, кому и за сколько до визита отправлять напоминание. Напоминания строятся от времени начала записи и отправляются автоматически. Если время уже прошло, уведомление задним числом не отправляется. Для ранее созданных записей сохраните настройки ещё раз.'))
                    ->schema([
                        Repeater::make('client_reminders')
                            ->label(__('Клиенту'))
                            ->schema($this->reminderSchema(true))
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel(__('Добавить напоминание')),
                        Repeater::make('specialist_reminders')
                            ->label(__('Себе / специалисту'))
                            ->schema($this->reminderSchema())
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel(__('Добавить напоминание')),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make(__('B2B-встречи'))
                    ->description(__('Подключите Zoom для автоматического создания встреч. Если подключение не настроено, используйте ручную ссылку.'))
                    ->schema([
                        TextInput::make('b2b_sales_call_duration_minutes')
                            ->label(__('Длительность вводной B2B-встречи (минуты)'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->helperText(__('Используется только для B2B-встреч. Длительность обычной консультации задаётся в самой услуге. Для автоматической Zoom-встречи действует ограничение тарифа Zoom.'))
                            ->nullable(),
                        Checkbox::make('b2b_zoom_host_licensed')
                            ->label(__('У Zoom-хоста есть лицензия Meetings'))
                            ->helperText(__('Не включайте, если хост использует бесплатный тариф Zoom.')),
                        Placeholder::make('zoom_instructions')
                            ->label(__('Что увидит клиент'))
                            ->content(__('Встреча создаётся автоматически, а ссылка появится у клиента.')),
                        Placeholder::make('zoom_status')
                            ->label(__('Текущее состояние'))
                            ->content(function (): string {
                                $configuration = app(GetB2bZoomConfiguration::class)->handle();
                                $readiness = app(GetB2bSalesCallReadiness::class)->handle();

                                return __(':zoom · длительность: :duration · календарь: :calendar', [
                                    'zoom' => $configuration['configured'] ? __('Zoom подключён') : __('Zoom не подключён'),
                                    'duration' => $readiness['durationConfigured'] ? __('настроена') : __('не настроена'),
                                    'calendar' => $readiness['calendarConfigured'] ? __('настроен') : __('не настроен'),
                                ]);
                            }),
                        Checkbox::make('zoom_enabled')
                            ->label(__('Разрешить автоматическое создание Zoom-встреч'))
                            ->helperText(__('Включите после заполнения данных Zoom. Если не включать, используйте ручную ссылку.'))
                            ->columnSpanFull(),
                        TextInput::make('zoom_account_id')
                            ->label('Account ID')
                            ->maxLength(255),
                        TextInput::make('zoom_client_id')
                            ->label('Client ID')
                            ->maxLength(255),
                        TextInput::make('zoom_client_secret')
                            ->label('Client Secret')
                            ->password()
                            ->revealable()
                            ->maxLength(2048)
                            ->helperText(__('Оставьте пустым, чтобы не менять текущий ключ.')),
                        TextInput::make('zoom_host_user_id')
                            ->label(__('User ID ведущего Zoom'))
                            ->maxLength(255)
                            ->helperText(__('Укажите пользователя Zoom, от имени которого создаются встречи.')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('Сохранить настройки'))
                        ->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $data = null;

        try {
            $data = $this->form->getState();

            DB::transaction(function () use ($actor, $data): void {
                app(SetBookingLeadTime::class)->handle($actor, (int) $data['lead_time_minutes']);
                app(SetBookingCancellationCutoff::class)->handle($actor, (int) $data['cancellation_cutoff_minutes']);
                app(SetOrganizationSetting::class)->handle(
                    $actor,
                    OrganizationSettingKey::DefaultTimezone,
                    (string) $data['default_timezone'],
                );
                app(SetOrganizationSetting::class)->handle(
                    $actor,
                    OrganizationSettingKey::PatientNotificationRecipients,
                    (string) ($data['patient_notification_recipients'] ?? PatientNotificationRecipientPolicy::Both->value),
                );
                app(SetOrganizationSetting::class)->handle(
                    $actor,
                    OrganizationSettingKey::HomeVisitOccupiedBufferMinutes,
                    (int) ($data['home_visit_occupied_buffer_minutes'] ?? 0),
                );
                if (isset($data['b2b_sales_call_duration_minutes']) && $data['b2b_sales_call_duration_minutes'] !== '') {
                    app(SetOrganizationSetting::class)->handle(
                        $actor,
                        OrganizationSettingKey::B2bSalesCallDurationMinutes,
                        (int) $data['b2b_sales_call_duration_minutes'],
                    );
                } else {
                    app(ClearOrganizationSetting::class)->handle(
                        $actor,
                        OrganizationSettingKey::B2bSalesCallDurationMinutes,
                    );
                }
                app(SetOnlineConsultationService::class)->handle(
                    $actor,
                    isset($data['online_consultation_service_id']) && $data['online_consultation_service_id'] !== ''
                        ? (int) $data['online_consultation_service_id']
                        : null,
                );
                if (array_key_exists('b2b_zoom_host_licensed', $data)) {
                    app(SetOrganizationSetting::class)->handle(
                        $actor,
                        OrganizationSettingKey::B2bZoomHostLicensed,
                        (bool) $data['b2b_zoom_host_licensed'],
                    );
                }
                if (isset($data['office_location']) && trim((string) $data['office_location']) !== '') {
                    $officeAddress = trim((string) $data['office_location']);
                    app(SetOrganizationSetting::class)->handle(
                        $actor,
                        OrganizationSettingKey::OfficeLocation,
                        $officeAddress,
                    );

                    $defaultLocation = WorkingLocation::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->where('is_active', true)
                        ->orderByDesc('is_default_office')
                        ->orderBy('name')
                        ->first();

                    if ($defaultLocation instanceof WorkingLocation) {
                        app(UpdateWorkingLocation::class)->handle(
                            actor: $actor,
                            location: $defaultLocation,
                            name: $defaultLocation->name,
                            address: $officeAddress,
                            timezone: $defaultLocation->timezone,
                            latitude: $defaultLocation->latitude === null ? null : (float) $defaultLocation->latitude,
                            longitude: $defaultLocation->longitude === null ? null : (float) $defaultLocation->longitude,
                            mapUrl: $defaultLocation->map_url,
                            isActive: true,
                            isDefaultOffice: true,
                        );
                    }
                } else {
                    app(ClearOrganizationSetting::class)->handle(
                        $actor,
                        OrganizationSettingKey::OfficeLocation,
                    );
                }
                app(UpdateAppointmentReminders::class)->handle($actor, $data);
                $zoom = app(GetB2bZoomConfiguration::class)->handle();
                $hasZoomInput = $zoom['exists']
                    || filled($data['zoom_account_id'] ?? null)
                    || filled($data['zoom_client_id'] ?? null)
                    || filled($data['zoom_client_secret'] ?? null)
                    || filled($data['zoom_host_user_id'] ?? null)
                    || (bool) ($data['zoom_enabled'] ?? false);
                if ($hasZoomInput) {
                    app(SaveB2bZoomConfiguration::class)->handle(
                        actor: $actor,
                        accountId: (string) ($data['zoom_account_id'] ?? ''),
                        clientId: (string) ($data['zoom_client_id'] ?? ''),
                        clientSecret: is_string($data['zoom_client_secret'] ?? null) ? $data['zoom_client_secret'] : null,
                        hostUserId: (string) ($data['zoom_host_user_id'] ?? ''),
                        enabled: (bool) ($data['zoom_enabled'] ?? false),
                    );
                }
            });
        } catch (ValidationException $exception) {
            $this->form->fill(ScheduleImpactPreview::mergeValidationPreview($this->safeFormState($data), $exception));

            throw $exception;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'schedule' => [$exception->getMessage()],
            ]);
        } finally {
            $this->scrubZoomClientSecret();
        }

        Notification::make()
            ->success()
            ->title(__('Расписание сохранено'))
            ->send();
    }

    /** @return array<int, string> */
    private function onlineConsultationServices(): array
    {
        return Service::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('is_active', true)
            ->where('catalog_type', CatalogItemType::Service->value)
            ->where('payment_requirement', ServicePaymentRequirement::PrepayFull->value)
            ->whereJsonContains('formats', 'online')
            ->whereHas('specialistServiceAssignments', fn ($query) => $query->whereHas(
                'specialist',
                fn ($specialists) => $specialists->where('organization_id', app(OrganizationContext::class)->id())
                    ->where('is_active', true),
            ))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /** @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    private function safeFormState(?array $data): array
    {
        if ($data === null) {
            $state = $this->form->getRawState();
            $data = $state instanceof Arrayable ? $state->toArray() : $state;
        }

        $data['zoom_client_secret'] = null;

        return $data;
    }

    private function scrubZoomClientSecret(): void
    {
        if ($this->data !== null) {
            $this->data['zoom_client_secret'] = null;
        }
    }

    /** @return list<array{is_enabled: bool, offset_value: int, offset_unit: string}> */
    private function reminderRows(string $recipientType): array
    {
        $rows = AppointmentReminder::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('recipient_type', $recipientType)
            ->orderBy('offset_value')
            ->orderBy('offset_unit')
            ->get()
            ->map(fn (AppointmentReminder $reminder): array => [
                'is_enabled' => $reminder->is_enabled,
                'offset_value' => $reminder->offset_value,
                'offset_unit' => $reminder->offset_unit->value,
                'medical_profile_prompt' => $reminder->medical_profile_prompt,
            ])
            ->values()
            ->all();

        return array_values($rows);
    }

    /** @return list<Component> */
    private function reminderSchema(bool $client = false): array
    {
        return [
            Checkbox::make('is_enabled')->label(__('Включено'))->default(true),
            TextInput::make('offset_value')
                ->label(__('За сколько до визита'))
                ->integer()
                ->minValue(1)
                ->required(),
            Select::make('offset_unit')
                ->label(__('Единица времени'))
                ->options([
                    ScenarioDelayUnit::Minutes->value => __('минут'),
                    ScenarioDelayUnit::Hours->value => __('часов'),
                    ScenarioDelayUnit::Days->value => __('дней'),
                ])
                ->required(),
            Checkbox::make('medical_profile_prompt')
                ->label(__('Предложить заполнить карту здоровья, если она нужна'))
                ->default(false)
                ->visible($client)
                ->dehydrated($client)
                ->columnSpanFull(),
        ];
    }
}
