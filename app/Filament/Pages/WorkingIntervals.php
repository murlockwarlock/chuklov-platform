<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Filament\Support\ScheduleImpactPreview;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Application\UpdateSpecialistViewerTimezone;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class WorkingIntervals extends LocalizedPage
{
    protected static ?string $title = 'Рабочие интервалы';

    protected static ?string $navigationLabel = 'Рабочие интервалы';

    protected ?string $subheading = 'Здесь настраиваются регулярные рабочие интервалы выбранного специалиста.';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Записи';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.working-intervals';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public ?string $deviceTimezone = null;

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
        $viewerSpecialist = $this->currentViewerSpecialist();
        $specialistId = $viewerSpecialist?->getKey() ?? Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())->where('is_active', true)
            ->orderBy('display_name')->orderBy('id')->value('id');
        $specialist = $specialistId === null ? null : Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())->find((int) $specialistId);
        $this->form->fill([
            'specialist_id' => $specialistId,
            'viewer_timezone' => $viewerSpecialist?->viewer_timezone ?? $specialist?->viewer_timezone,
            'viewer_timezone_suggestion' => $viewerSpecialist?->viewer_timezone_suggestion ?? $specialist?->viewer_timezone_suggestion,
            'working_hours' => $specialist === null ? [] : $this->workingHours((int) $specialist->getKey()),
            'working_hours_digest' => $specialist === null ? null : app(SetSpecialistWorkingHours::class)->digest($specialist),
            'clear_working_hours' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('specialist_id')
                ->label(__('Специалист'))
                ->options(fn (): array => $this->specialists())
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $specialist = $state === null ? null : Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->find((int) $state);
                    $set('working_hours', $specialist instanceof Specialist ? $this->workingHours((int) $state) : []);
                    $set(
                        'working_hours_digest',
                        $specialist instanceof Specialist ? app(SetSpecialistWorkingHours::class)->digest($specialist) : null,
                    );
                    $set('clear_working_hours', false);

                    if ($this->currentViewerSpecialist() !== null) {
                        return;
                    }

                    $specialist = $state === null ? null : Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->find((int) $state);
                    $set('viewer_timezone', $specialist?->viewer_timezone);
                    $set('viewer_timezone_suggestion', $specialist?->viewer_timezone_suggestion);
                }),
            Select::make('viewer_timezone')
                ->label(__('Часовой пояс CRM'))
                ->options(fn (Get $get): array => TimezoneOptions::options(
                    current: $get('viewer_timezone'),
                    organization: app(OrganizationContext::class)->defaultTimezone(),
                ))
                ->searchable()
                ->nullable()
                ->helperText(__('Меняет только отображение времени в CRM и уведомлениях. Уже созданные записи не сдвигаются.')),
            Repeater::make('working_hours')
                ->label(__('Рабочие интервалы'))
                ->schema([
                    Select::make('weekday')
                        ->label(__('День недели'))
                        ->options([
                            1 => __('Понедельник'),
                            2 => __('Вторник'),
                            3 => __('Среда'),
                            4 => __('Четверг'),
                            5 => __('Пятница'),
                            6 => __('Суббота'),
                            7 => __('Воскресенье'),
                        ])
                        ->required(),
                    TimePicker::make('start_time')
                        ->label(__('Начало'))
                        ->seconds(false)
                        ->required(),
                    TimePicker::make('end_time')
                        ->label(__('Окончание'))
                        ->seconds(false)
                        ->required(),
                    DatePicker::make('starts_on')
                        ->label(__('Действует с'))
                        ->format('Y-m-d')
                        ->displayFormat('d.m.Y')
                        ->native(false)
                        ->nullable()
                        ->default(today())
                        ->helperText(__('Пусто — с самого начала.')),
                    DatePicker::make('ends_on')
                        ->label(__('До'))
                        ->format('Y-m-d')
                        ->displayFormat('d.m.Y')
                        ->native(false)
                        ->nullable()
                        ->afterOrEqual('starts_on')
                        ->helperText(__('Пусто — бессрочно.')),
                ])
                ->columns(5)
                ->defaultItems(0)
                ->addActionLabel(__('+ Добавить интервал'))
                ->reorderable(false)
                ->columnSpanFull(),
            Hidden::make('working_hours_digest')->dehydrated()->required()->string()->rules(['size:64']),
            Placeholder::make('specialist_schedule_timezone')
                ->label(__('Часовой пояс графика'))
                ->content(fn (): string => $this->specialistScheduleTimezoneLabel())
                ->columnSpanFull(),
            Checkbox::make('clear_working_hours')
                ->label(__('Удалить всё рабочее расписание'))
                ->helperText(__('Отметьте и сохраните, только если действительно нужно удалить все рабочие часы выбранного специалиста.'))
                ->columnSpanFull(),
            ...ScheduleImpactPreview::components(),
        ])->statePath('data');
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
                        ->label(__('Сохранить расписание'))
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
            $specialist = Specialist::query()->where('organization_id', app(OrganizationContext::class)->id())
                ->findOrFail((int) $data['specialist_id']);
            DB::transaction(function () use ($actor, $specialist, $data): void {
                $hours = $data['working_hours'] ?? [];
                if ($hours !== [] || ($data['clear_working_hours'] ?? false)) {
                    app(SetSpecialistWorkingHours::class)->handle(
                        $actor, $specialist, ($data['clear_working_hours'] ?? false) ? [] : $hours,
                        (bool) ($data['acknowledge_impact'] ?? false),
                        isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                        isset($data['working_hours_digest']) ? (string) $data['working_hours_digest'] : null,
                    );
                }
                app(UpdateSpecialistViewerTimezone::class)->handle(
                    actor: $actor, specialist: $this->currentViewerSpecialist() ?? $specialist,
                    timezone: filled($data['viewer_timezone'] ?? null) ? (string) $data['viewer_timezone'] : null,
                    source: filled($data['viewer_timezone'] ?? null) ? 'manual' : 'organization',
                );
            });
            $this->data['working_hours_digest'] = app(SetSpecialistWorkingHours::class)->digest($specialist);
        } catch (ValidationException $exception) {
            $state = $this->form->getRawState();
            $this->form->fill(ScheduleImpactPreview::mergeValidationPreview(
                $data ?? ($state instanceof Arrayable ? $state->toArray() : $state), $exception,
            ));
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['working_hours' => [$this->humanScheduleError($exception->getMessage())]]);
        }
        Notification::make()->success()->title(__('Расписание сохранено'))->send();
    }

    public function useDeviceTimezone(?string $timezone = null): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        if ($timezone !== null) {
            $this->deviceTimezone = $timezone;
        }
        if ($this->deviceTimezone === null || $this->deviceTimezone === '') {
            return;
        }

        $specialist = $this->currentViewerSpecialist() ?? $this->selectedSpecialist();
        if (! $specialist instanceof Specialist) {
            return;
        }

        app(UpdateSpecialistViewerTimezone::class)->handle(
            actor: $actor,
            specialist: $specialist,
            timezone: $this->deviceTimezone,
            source: 'device',
        );
        $this->data['viewer_timezone'] = $this->deviceTimezone;
        $this->data['viewer_timezone_suggestion'] = null;
        $this->deviceTimezone = null;
    }

    public function dismissDeviceTimezone(): void
    {
        $this->data['viewer_timezone_suggestion'] = null;
        $this->deviceTimezone = null;
    }

    public function currentViewerTimezone(): string
    {
        $specialist = $this->currentViewerSpecialist() ?? $this->selectedSpecialist();

        return $specialist instanceof Specialist && $specialist->viewer_timezone !== null
            ? $specialist->viewer_timezone
            : app(OrganizationContext::class)->defaultTimezone();
    }

    private function selectedSpecialist(): ?Specialist
    {
        $specialistId = $this->data['specialist_id'] ?? null;

        return $specialistId === null
            ? null
            : Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->find((int) $specialistId);
    }

    private function currentViewerSpecialist(): ?Specialist
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return null;
        }

        return Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('staff_user_id', $actor->getKey())
            ->where('is_active', true)
            ->orderBy('display_name')
            ->orderBy('id')
            ->first();
    }

    /** @return array<int, string> */
    private function specialists(): array
    {
        return Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('is_active', true)
            ->orderBy('display_name')
            ->orderBy('id')
            ->pluck('display_name', 'id')
            ->all();
    }

    public function specialistScheduleTimezoneLabel(): string
    {
        $specialist = $this->selectedSpecialist();
        $timezone = $specialist instanceof Specialist ? $specialist->timezone : app(OrganizationContext::class)->defaultTimezone();
        $offsetHours = now($timezone)->getOffset() / 3600;
        $offset = $offsetHours === 0.0
            ? 'UTC'
            : 'UTC'.($offsetHours > 0 ? '+' : '').rtrim(rtrim(number_format($offsetHours, 2, '.', ''), '0'), '.');

        return __('График задан по времени: ').TimezoneOptions::label($timezone).' ('.$offset.') · '.$timezone;
    }

    private function humanScheduleError(string $message): string
    {
        return str_contains(mb_strtolower($message), 'overlap')
            ? (string) __('Рабочие интервалы не должны пересекаться.')
            : $message;
    }

    /** @return list<array{weekday: int, start_time: string, end_time: string, starts_on: string|null, ends_on: string|null}> */
    private function workingHours(int $specialistId): array
    {
        $hours = SpecialistWorkingHour::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('specialist_id', $specialistId)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();
        $rows = [];
        foreach ($hours as $hour) {
            $rows[] = [
                'weekday' => $hour->weekday,
                'start_time' => substr((string) $hour->start_time, 0, 5),
                'end_time' => substr((string) $hour->end_time, 0, 5),
                'starts_on' => $hour->starts_on?->toDateString(),
                'ends_on' => $hour->ends_on?->toDateString(),
            ];
        }

        return $rows;
    }
}
