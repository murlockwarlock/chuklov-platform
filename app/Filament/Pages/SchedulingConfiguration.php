<?php

namespace App\Filament\Pages;

use App\Filament\Support\ScheduleImpactPreview;
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
use App\Modules\Scheduling\Application\GetBookingCancellationCutoff;
use App\Modules\Scheduling\Application\GetBookingLeadTime;
use App\Modules\Scheduling\Application\SetBookingCancellationCutoff;
use App\Modules\Scheduling\Application\SetBookingLeadTime;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class SchedulingConfiguration extends Page
{
    protected static ?string $title = 'Настройки расписания';

    protected static ?string $navigationLabel = 'Настройки расписания';

    protected ?string $subheading = 'Здесь задаются длительность B2B-разговора, специалист и его рабочие часы. Исключения и недоступное время настраиваются в соседних разделах группы «Записи». Автоматический Zoom использует защищённое подключение без показа секретов; для отдельных разговоров доступна ручная HTTPS-ссылка.';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Записи';

    protected static ?int $navigationSort = 2;

    /** @var array{specialist_id: int|null, lead_time_minutes: int, cancellation_cutoff_minutes: int, b2b_sales_call_duration_minutes: int|null, b2b_zoom_host_licensed: bool, office_location: string|null, zoom_enabled: bool, zoom_account_id: string|null, zoom_client_id: string|null, zoom_client_secret: string|null, zoom_host_user_id: string|null, working_hours: list<array{weekday: int, start_time: string, end_time: string}>}|null */
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
        $specialistId = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->orderBy('display_name')
            ->value('id');
        $zoom = app(GetB2bZoomConfiguration::class)->handle();

        $this->form->fill([
            'specialist_id' => $specialistId,
            'lead_time_minutes' => app(GetBookingLeadTime::class)->handle(),
            'cancellation_cutoff_minutes' => app(GetBookingCancellationCutoff::class)->handle(),
            'b2b_sales_call_duration_minutes' => app(GetB2bSalesCallDuration::class)->handle(),
            'b2b_zoom_host_licensed' => (bool) app(OrganizationContext::class)->organization()->settings()
                ->where('setting_key', OrganizationSettingKey::B2bZoomHostLicensed->value)
                ->value('boolean_value'),
            'office_location' => app(OrganizationContext::class)->organization()->settings()
                ->where('setting_key', OrganizationSettingKey::OfficeLocation->value)
                ->value('string_value'),
            'zoom_enabled' => $zoom['enabled'],
            'zoom_account_id' => $zoom['accountId'],
            'zoom_client_id' => $zoom['clientId'],
            'zoom_client_secret' => null,
            'zoom_host_user_id' => $zoom['hostUserId'],
            'working_hours' => $specialistId === null ? [] : $this->workingHours((int) $specialistId),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('specialist_id')
                    ->label('Специалист')
                    ->options(fn (): array => $this->specialists())
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('working_hours', $state === null ? [] : $this->workingHours((int) $state));
                    }),
                TextInput::make('lead_time_minutes')
                    ->label('Минимальный срок до записи (минуты)')
                    ->integer()
                    ->minValue(0)
                    ->helperText('За сколько минут до начала визита клиент может оформить запись.')
                    ->required(),
                TextInput::make('cancellation_cutoff_minutes')
                    ->label('Срок отмены или переноса (минуты)')
                    ->integer()
                    ->minValue(0)
                    ->helperText('За сколько минут до визита клиент может бесплатно отменить или перенести запись.')
                    ->required(),
                TextInput::make('b2b_sales_call_duration_minutes')
                    ->label('Длительность B2B-разговора (минуты)')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(1440)
                    ->helperText('Без этого значения клиентские слоты не публикуются. Бесплатный Zoom поддерживает автоматические разговоры до 40 минут; для большей длительности отметьте лицензированный хост.')
                    ->nullable(),
                Checkbox::make('b2b_zoom_host_licensed')
                    ->label('У Zoom-хоста есть лицензия Meetings')
                    ->helperText('Не включайте, если хост использует бесплатный тариф Zoom.'),
                TextInput::make('office_location')
                    ->label('Адрес клиники')
                    ->helperText('Адрес для очных визитов в клинике.')
                    ->maxLength(500),
                Section::make('Подключение Zoom')
                    ->description('Создайте приложение Server-to-Server OAuth в Zoom Marketplace и вставьте сюда его Account ID, Client ID, Client Secret и User ID ведущего. Секрет не показывается после сохранения; пустое поле при редактировании сохраняет прежний секрет.')
                    ->schema([
                        Placeholder::make('zoom_instructions')
                            ->label('Что увидит клиент')
                            ->content('Система создаст встречу в подключённом Zoom-аккаунте автоматически. После синхронизации ссылка появится у клиента.'),
                        Placeholder::make('zoom_status')
                            ->label('Текущее состояние')
                            ->content(function (): string {
                                $configuration = app(GetB2bZoomConfiguration::class)->handle();
                                $readiness = app(GetB2bSalesCallReadiness::class)->handle();

                                return ($configuration['configured'] ? 'Zoom подключён' : 'Zoom не подключён')
                                    .' · длительность: '.($readiness['durationConfigured'] ? 'настроена' : 'не настроена')
                                    .' · календарь: '.($readiness['calendarConfigured'] ? 'настроен' : 'не настроен');
                            }),
                        Checkbox::make('zoom_enabled')
                            ->label('Разрешить автоматическое создание Zoom-встреч')
                            ->helperText('Включайте после заполнения всех полей. Без активного подключения клиенту доступна только ручная ссылка.')
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
                            ->helperText('Оставьте пустым при редактировании, чтобы сохранить сохранённый секрет.'),
                        TextInput::make('zoom_host_user_id')
                            ->label('User ID ведущего Zoom')
                            ->maxLength(255)
                            ->helperText('Это User ID пользователя, от имени которого создаются встречи.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Repeater::make('working_hours')
                    ->label('Рабочие часы')
                    ->schema([
                        Select::make('weekday')
                            ->label('День недели')
                            ->options([
                                1 => 'Понедельник',
                                2 => 'Вторник',
                                3 => 'Среда',
                                4 => 'Четверг',
                                5 => 'Пятница',
                                6 => 'Суббота',
                                7 => 'Воскресенье',
                            ])
                            ->required(),
                        TimePicker::make('start_time')
                            ->label('Начало')
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('end_time')
                            ->label('Окончание')
                            ->seconds(false)
                            ->required(),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->addActionLabel('Добавить часы')
                    ->reorderable(false)
                    ->columnSpanFull(),
                ...ScheduleImpactPreview::components(),
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
                        ->label('Сохранить расписание')
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
            $specialist = Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->findOrFail((int) $data['specialist_id']);

            DB::transaction(function () use ($actor, $data, $specialist): void {
                app(SetBookingLeadTime::class)->handle($actor, (int) $data['lead_time_minutes']);
                app(SetBookingCancellationCutoff::class)->handle($actor, (int) $data['cancellation_cutoff_minutes']);
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
                if (array_key_exists('b2b_zoom_host_licensed', $data)) {
                    app(SetOrganizationSetting::class)->handle(
                        $actor,
                        OrganizationSettingKey::B2bZoomHostLicensed,
                        (bool) $data['b2b_zoom_host_licensed'],
                    );
                }
                if (isset($data['office_location']) && trim((string) $data['office_location']) !== '') {
                    app(SetOrganizationSetting::class)->handle(
                        $actor,
                        OrganizationSettingKey::OfficeLocation,
                        trim((string) $data['office_location']),
                    );
                }
                app(SetSpecialistWorkingHours::class)->handle(
                    $actor,
                    $specialist,
                    $data['working_hours'] ?? [],
                    (bool) ($data['acknowledge_impact'] ?? false),
                    isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                );
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
        } finally {
            $this->scrubZoomClientSecret();
        }

        Notification::make()
            ->success()
            ->title('Расписание сохранено')
            ->send();
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

    /** @return array<int, string> */
    private function specialists(): array
    {
        return Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->orderBy('display_name')
            ->pluck('display_name', 'id')
            ->all();
    }

    /** @return list<array{weekday: int, start_time: string, end_time: string}> */
    private function workingHours(int $specialistId): array
    {
        $hours = SpecialistWorkingHour::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('specialist_id', $specialistId)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->map(fn (SpecialistWorkingHour $hour): array => [
                'weekday' => $hour->weekday,
                'start_time' => substr((string) $hour->start_time, 0, 5),
                'end_time' => substr((string) $hour->end_time, 0, 5),
            ])
            ->values()
            ->all();

        return array_values($hours);
    }
}
