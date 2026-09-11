<?php

namespace App\Filament\Pages;

use App\Filament\Support\ScheduleImpactPreview;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Application\GetScheduleCalendar;
use App\Modules\Scheduling\Application\ResolveSpecialistWorkingHours;
use App\Modules\Scheduling\Application\SetScheduleExceptionSet;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use LogicException;
use UnitEnum;

final class WorkSchedule extends Page
{
    protected static ?string $title = 'График работы';

    protected static ?string $navigationLabel = 'График работы';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Записи';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.work-schedule';

    #[Url(as: 'specialist_id', history: true, keep: true, nullable: true)]
    public ?int $specialistId = null;

    #[Url(as: 'month', history: true, keep: true)]
    public string $month = '';

    /** @var list<string> */
    public array $selectedDates = [];

    public ?string $pendingPreset = null;

    public ?string $selectedDate = null;

    public ?string $selectedScheduleDate = null;

    public string $overrideType = 'working';

    /** @var list<array{start_time: string, end_time: string}> */
    public array $overrideIntervals = [];

    public string $overrideReason = '';

    public ?int $selectedExceptionId = null;

    public ?string $impactDigest = null;

    /** @var list<array<string, mixed>> */
    public array $impactBookings = [];

    public bool $acknowledgeImpact = false;

    public string $errorMessage = '';

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
        $this->specialistId = $this->selectedSpecialist()?->getKey()
            ?? $this->currentViewerSpecialist()?->getKey()
            ?? Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->where('is_active', true)
                ->orderBy('display_name')
                ->orderBy('id')
                ->value('id');
        $requestedMonth = $this->validMonth($this->month);
        $this->month = $requestedMonth
            ?? CarbonImmutable::now($this->specialistScheduleTimezone())->format('Y-m');
        $this->clearSelection();
    }

    public function updatedSpecialistId(): void
    {
        if (! $this->selectedSpecialist() instanceof Specialist) {
            $this->specialistId = Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->where('is_active', true)
                ->orderBy('display_name')
                ->orderBy('id')
                ->value('id');
        }

        $this->month = CarbonImmutable::now($this->specialistScheduleTimezone())->format('Y-m');
        $this->clearSelection();
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthDate()->subMonth()->format('Y-m');
        $this->clearSelection();
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthDate()->addMonth()->format('Y-m');
        $this->clearSelection();
    }

    public function currentMonth(): void
    {
        $this->month = CarbonImmutable::now($this->specialistScheduleTimezone())->format('Y-m');
        $this->clearSelection();
    }

    public function applyPreset(string $preset): void
    {
        if (! in_array($preset, ['weekdays', 'all', 'even', 'odd'], true)) {
            return;
        }

        $this->selectedDates = $this->datesForPreset($preset);
        $this->pendingPreset = $preset;
        $this->loadEditorForSelection();
        $this->clearImpact();
    }

    public function toggleDate(string $date): void
    {
        if (! $this->isDateInCurrentMonth($date)) {
            return;
        }

        $wasEmpty = $this->selectedDates === [];
        if (in_array($date, $this->selectedDates, true)) {
            $this->selectedDates = array_values(array_diff($this->selectedDates, [$date]));
            if ($this->selectedDates === []) {
                $this->clearSelection();

                return;
            }
            if ($this->selectedDate === $date) {
                $this->selectedDate = $this->selectedDates[0];
                $this->selectedScheduleDate = $this->selectedDate;
                $this->loadEditorForSelection();
            }
        } else {
            $this->selectedDates[] = $date;
            sort($this->selectedDates);
            if ($wasEmpty) {
                $this->selectedDate = $date;
                $this->selectedScheduleDate = $date;
                $this->loadEditorForSelection();
            }
        }

        $this->pendingPreset = null;
        $this->clearImpact();
    }

    public function editDate(string $date): void
    {
        $this->toggleDate($date);
    }

    public function clearSelectedDates(): void
    {
        if ($this->selectedDates === []) {
            $this->errorMessage = 'Выберите даты, которые нужно очистить.';

            return;
        }

        $this->overrideType = ScheduleExceptionType::DayOff->value;
        $this->saveOverride();
    }

    public function returnToRegularSchedule(): void
    {
        if ($this->selectedDates === []) {
            $this->errorMessage = 'Выберите дату, которую нужно вернуть по графику.';

            return;
        }

        $this->overrideType = 'working';
        $this->saveOverride();
    }

    public function addOverrideInterval(): void
    {
        $this->overrideIntervals[] = ['start_time' => '09:00', 'end_time' => '10:00'];
    }

    public function removeOverrideInterval(int $index): void
    {
        if (! array_key_exists($index, $this->overrideIntervals)) {
            return;
        }

        unset($this->overrideIntervals[$index]);
        $this->overrideIntervals = array_values($this->overrideIntervals);
    }

    public function saveOverride(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $specialist = $this->selectedSpecialist();

        if (! $specialist instanceof Specialist || $this->selectedDates === []) {
            $this->errorMessage = 'Выберите специалиста и даты.';

            return;
        }

        if ($this->overrideType === ScheduleExceptionType::CustomWindow->value && $this->overrideIntervals === []) {
            $this->errorMessage = 'Добавьте хотя бы один рабочий интервал.';

            return;
        }

        try {
            $definitions = [];
            foreach ($this->selectedDates as $date) {
                $definitions[$date] = match ($this->overrideType) {
                    'working' => [],
                    ScheduleExceptionType::DayOff->value => [[
                        'exception_date' => $date,
                        'exception_type' => ScheduleExceptionType::DayOff->value,
                        'reason' => $this->normalizedReason(),
                    ]],
                    ScheduleExceptionType::CustomWindow->value => array_map(
                        fn (array $interval): array => [
                            'exception_date' => $date,
                            'exception_type' => ScheduleExceptionType::CustomWindow->value,
                            'start_time' => $interval['start_time'] ?? null,
                            'end_time' => $interval['end_time'] ?? null,
                            'reason' => $this->normalizedReason(),
                        ],
                        $this->overrideIntervals,
                    ),
                    default => throw new \InvalidArgumentException('Выберите корректный режим изменения.'),
                };
            }

            app(SetScheduleExceptionSet::class)->handle(
                actor: $actor,
                specialist: $specialist,
                definitionsByDate: $definitions,
                acknowledgeImpact: $this->acknowledgeImpact,
                acknowledgedImpactDigest: $this->impactDigest,
            );
        } catch (ValidationException $exception) {
            $this->setImpactFromException($exception);

            return;
        } catch (\InvalidArgumentException $exception) {
            $this->errorMessage = $this->humanScheduleError($exception->getMessage());

            return;
        }

        $this->loadEditorForSelection();
        $this->clearImpact();
        Notification::make()->success()->title('Изменения сохранены')->send();
    }

    /** @return array<string, array{date: string, weekday: int, is_working: bool, exception_type: string|null, intervals: list<array{start: string, end: string, start_minutes: int, end_minutes: int}>}> */
    public function getScheduleDaysProperty(): array
    {
        $specialist = $this->selectedSpecialist();

        return $specialist instanceof Specialist
            ? app(GetScheduleCalendar::class)->forSpecialist(
                specialist: $specialist,
                dateFrom: $this->monthDate()->startOfMonth()->toDateString(),
                dateTo: $this->monthDate()->endOfMonth()->toDateString(),
                displayTimezone: $this->specialistScheduleTimezone(),
            )
            : [];
    }

    /** @return list<array{date: string, weekday: int, is_working: bool, exception_type: string|null, intervals: list<array{start: string, end: string, start_minutes: int, end_minutes: int}>}|null> */
    public function getMonthCellsProperty(): array
    {
        $monthStart = $this->monthDate()->startOfMonth();
        $cells = array_fill(0, $monthStart->dayOfWeekIso - 1, null);

        for ($day = 1; $day <= $monthStart->daysInMonth; $day++) {
            $date = $monthStart->setDay($day)->toDateString();
            $cells[] = $this->scheduleDays[$date] ?? [
                'date' => $date,
                'weekday' => $monthStart->setDay($day)->dayOfWeekIso,
                'is_working' => false,
                'exception_type' => null,
                'intervals' => [],
            ];
        }

        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        return $cells;
    }

    /** @return array<int, string> */
    public function getSpecialistOptionsProperty(): array
    {
        return Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('is_active', true)
            ->orderBy('display_name')
            ->orderBy('id')
            ->pluck('display_name', 'id')
            ->all();
    }

    public function monthLabel(): string
    {
        $months = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
            7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];
        $date = $this->monthDate();

        return $months[$date->month].' '.$date->year;
    }

    public function isToday(string $date): bool
    {
        return $date === CarbonImmutable::now($this->specialistScheduleTimezone())->toDateString();
    }

    public function isSelectedDate(string $date): bool
    {
        return in_array($date, $this->selectedDates, true);
    }

    public function crmTimezone(): string
    {
        return IanaTimezone::from(app(OrganizationContext::class)->defaultTimezone())->value;
    }

    public function specialistScheduleTimezone(): string
    {
        $specialist = $this->selectedSpecialist();
        $timezone = $specialist?->timezone ?? $this->crmTimezone();

        return IanaTimezone::from($timezone)->value;
    }

    public function specialistScheduleTimezoneLabel(): string
    {
        $timezone = $this->specialistScheduleTimezone();
        $offsetHours = CarbonImmutable::now($timezone)->getOffset() / 3600;
        $offset = $offsetHours === 0.0
            ? 'UTC'
            : 'UTC'.($offsetHours > 0 ? '+' : '').rtrim(rtrim(number_format($offsetHours, 2, '.', ''), '0'), '.');

        return 'График задан по времени: '.TimezoneOptions::label($timezone).' ('.$offset.') · '.$timezone;
    }

    public function selectedDatesLabel(): string
    {
        return implode(', ', array_map(
            static fn (string $date): string => CarbonImmutable::parse($date)->format('d.m.Y'),
            $this->selectedDates,
        ));
    }

    public function pendingPresetLabel(): ?string
    {
        return match ($this->pendingPreset) {
            'weekdays' => 'Будни',
            'all' => 'Все дни',
            'even' => 'Чётные даты',
            'odd' => 'Нечётные даты',
            default => null,
        };
    }

    public function isPresetWorkingDate(string $date): bool
    {
        return $this->isSelectedDate($date);
    }

    public function hasPendingImpact(): bool
    {
        return $this->impactBookings !== [];
    }

    private function datesForPreset(string $preset): array
    {
        $dates = [];
        $month = $this->monthDate();

        for ($day = 1; $day <= $month->daysInMonth; $day++) {
            $date = $month->setDay($day);
            $isSelected = match ($preset) {
                'weekdays' => $date->dayOfWeekIso <= 5,
                'all' => true,
                'even' => $date->day % 2 === 0,
                'odd' => $date->day % 2 === 1,
                default => false,
            };

            if ($isSelected) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    private function loadEditorForSelection(): void
    {
        $date = $this->selectedDates[0] ?? null;
        $this->selectedDate = $date;
        $this->selectedScheduleDate = $date;
        $this->selectedExceptionId = null;
        $this->overrideReason = '';
        $this->overrideType = 'working';
        $this->overrideIntervals = $date === null ? [] : $this->recurringIntervalsForDate($date);

        if ($date === null || $this->specialistId === null) {
            return;
        }

        $exceptions = $this->selectedExceptionRows($date);
        $first = $exceptions->first();
        $this->selectedExceptionId = $first?->getKey();
        $this->overrideReason = (string) ($first?->reason ?? '');

        if ($exceptions->contains(
            static fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::DayOff,
        )) {
            $this->overrideType = ScheduleExceptionType::DayOff->value;

            return;
        }

        $customIntervals = $exceptions
            ->filter(static fn (ScheduleException $exception): bool => $exception->exception_type === ScheduleExceptionType::CustomWindow)
            ->map(static fn (ScheduleException $exception): array => [
                'start_time' => substr((string) $exception->start_time, 0, 5),
                'end_time' => substr((string) $exception->end_time, 0, 5),
            ])
            ->values()
            ->all();

        if ($customIntervals !== []) {
            $this->overrideType = ScheduleExceptionType::CustomWindow->value;
            $this->overrideIntervals = $customIntervals;
        }
    }

    /** @return list<array{start_time: string, end_time: string}> */
    private function recurringIntervalsForDate(string $date): array
    {
        $localDate = LocalDate::from($date);
        $specialist = $this->selectedSpecialist();

        if (! $specialist instanceof Specialist) {
            return [];
        }

        $resolver = app(ResolveSpecialistWorkingHours::class);

        return collect($resolver->intervalsForDate(
            $resolver->forRange($specialist, $localDate, $localDate),
            $localDate,
        ))
            ->map(static fn (WallClockInterval $interval): array => [
                'start_time' => $interval->start,
                'end_time' => $interval->end,
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, ScheduleException> */
    private function selectedExceptionRows(string $date): Collection
    {
        return ScheduleException::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('specialist_id', $this->specialistId)
            ->where('exception_date', $date)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    private function selectedSpecialist(): ?Specialist
    {
        return $this->specialistId === null
            ? null
            : Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->where('is_active', true)
                ->whereKey($this->specialistId)
                ->first();
    }

    private function currentViewerSpecialist(): ?Specialist
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->where('staff_user_id', $actor->getKey())
                ->where('is_active', true)
                ->orderBy('display_name')
                ->orderBy('id')
                ->first()
            : null;
    }

    private function monthDate(): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $this->month.'-01',
            $this->specialistScheduleTimezone(),
        );

        if (! $date instanceof CarbonImmutable || $date->format('Y-m') !== $this->month) {
            return CarbonImmutable::now($this->specialistScheduleTimezone())->startOfMonth();
        }

        return $date;
    }

    private function validMonth(string $month): ?string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $month.'-01',
            $this->specialistScheduleTimezone(),
        );

        return $date instanceof CarbonImmutable && $date->format('Y-m') === $month
            ? $month
            : null;
    }

    private function isDateInCurrentMonth(string $date): bool
    {
        try {
            $localDate = LocalDate::from($date);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return str_starts_with($localDate->value, $this->month.'-');
    }

    private function normalizedReason(): ?string
    {
        $reason = trim($this->overrideReason);

        return $reason === '' ? null : $reason;
    }

    private function clearSelection(): void
    {
        $this->selectedDates = [];
        $this->selectedDate = null;
        $this->selectedScheduleDate = null;
        $this->selectedExceptionId = null;
        $this->overrideType = 'working';
        $this->overrideIntervals = [];
        $this->overrideReason = '';
        $this->pendingPreset = null;
        $this->clearImpact();
    }

    private function clearImpact(): void
    {
        $this->impactDigest = null;
        $this->impactBookings = [];
        $this->acknowledgeImpact = false;
        $this->errorMessage = '';
    }

    private function setImpactFromException(ValidationException $exception): void
    {
        $preview = ScheduleImpactPreview::stateFromValidationException($exception);
        $this->impactDigest = $preview['impact_digest'] ?? null;
        $this->impactBookings = $preview['schedule_impact_bookings'] ?? [];
        $this->acknowledgeImpact = false;
        $errors = $exception->errors();
        $this->errorMessage = $errors['schedule_impact'][0]
            ?? $errors['exception_date'][0]
            ?? $this->humanScheduleError($exception->getMessage());
    }

    private function humanScheduleError(string $message): string
    {
        return str_contains(mb_strtolower($message), 'overlap')
            ? 'Рабочие интервалы не должны пересекаться.'
            : $message;
    }
}
