<?php

namespace App\Filament\Pages;

use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class SchedulingConfiguration extends Page
{
    protected static ?string $navigationLabel = 'Working hours';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?int $navigationSort = 1;

    /** @var array{specialist_id: int|null, lead_time_minutes: int, cancellation_cutoff_minutes: int, office_location: string|null, working_hours: list<array{weekday: int, start_time: string, end_time: string}>}|null */
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

        $this->form->fill([
            'specialist_id' => $specialistId,
            'lead_time_minutes' => app(GetBookingLeadTime::class)->handle(),
            'cancellation_cutoff_minutes' => app(GetBookingCancellationCutoff::class)->handle(),
            'office_location' => app(OrganizationContext::class)->organization()->settings()
                ->where('setting_key', OrganizationSettingKey::OfficeLocation->value)
                ->value('string_value'),
            'working_hours' => $specialistId === null ? [] : $this->workingHours((int) $specialistId),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('specialist_id')
                    ->label('Specialist')
                    ->options(fn (): array => $this->specialists())
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('working_hours', $state === null ? [] : $this->workingHours((int) $state));
                    }),
                TextInput::make('lead_time_minutes')
                    ->label('Booking lead time (minutes)')
                    ->integer()
                    ->minValue(0)
                    ->required(),
                TextInput::make('cancellation_cutoff_minutes')
                    ->label('Cancellation/reschedule cutoff (minutes)')
                    ->integer()
                    ->minValue(0)
                    ->required(),
                TextInput::make('office_location')
                    ->label('Organization office location')
                    ->maxLength(500),
                Repeater::make('working_hours')
                    ->label('Recurring working hours')
                    ->schema([
                        Select::make('weekday')
                            ->label('Weekday')
                            ->options([
                                1 => 'Monday',
                                2 => 'Tuesday',
                                3 => 'Wednesday',
                                4 => 'Thursday',
                                5 => 'Friday',
                                6 => 'Saturday',
                                7 => 'Sunday',
                            ])
                            ->required(),
                        TimePicker::make('start_time')
                            ->label('Start')
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('end_time')
                            ->label('End')
                            ->seconds(false)
                            ->required(),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->addActionLabel('Add interval')
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
                        ->label('Save working hours')
                        ->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $data = $this->form->getState();
        $specialist = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->findOrFail((int) $data['specialist_id']);

        try {
            DB::transaction(function () use ($actor, $data, $specialist): void {
                app(SetBookingLeadTime::class)->handle($actor, (int) $data['lead_time_minutes']);
                app(SetBookingCancellationCutoff::class)->handle($actor, (int) $data['cancellation_cutoff_minutes']);
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
            });
        } catch (ValidationException $exception) {
            $this->form->fill(ScheduleImpactPreview::mergeValidationPreview($data, $exception));

            throw $exception;
        }

        Notification::make()
            ->success()
            ->title('Scheduling configuration saved')
            ->send();
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
