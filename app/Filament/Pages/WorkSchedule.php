<?php

namespace App\Filament\Pages;

use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Application\ApplyMonthlySchedulePreset;
use App\Modules\Scheduling\Application\CreateScheduleException;
use App\Modules\Scheduling\Application\DeleteScheduleException;
use App\Modules\Scheduling\Application\GetScheduleCalendar;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Application\UpdateScheduleException;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public ?int $specialistId = null;

    public string $month = '';

    /** @var list<int> */
    public array $selectedWeekdays = [];

    public ?string $pendingPreset = null;

    public string $startTime = '09:00';

    public string $endTime = '19:00';

    public bool $breakEnabled = false;

    public string $breakStart = '13:00';

    public string $breakEnd = '14:00';

    public ?string $selectedDate = null;

    public ?string $selectedScheduleDate = null;

    public string $overrideType = 'working';

    public string $overrideStart = '09:00';

    public string $overrideEnd = '19:00';

    public string $overrideReason = '';

    public ?int $selectedExceptionId = null;

    public ?string $impactDigest = null;

    public ?string $impactSource = null;

    public ?string $monthlyAcknowledgedImpactDigest = null;

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
        $this->specialistId = $this->currentViewerSpecialist()?->getKey()
            ?? Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->where('is_active', true)
                ->orderBy('display_name')
                ->value('id');
        $this->month = CarbonImmutable::now($this->crmTimezone())->format('Y-m');
        $this->loadScheduleState();
    }

    public function updatedSpecialistId(): void
    {
        $this->loadScheduleState();
        $this->clearImpact();
        $this->selectedDate = null;
        $this->selectedScheduleDate = null;
        $this->pendingPreset = null;
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthDate()->subMonth()->format('Y-m');
        $this->pendingPreset = null;
        $this->clearImpact();
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthDate()->addMonth()->format('Y-m');
        $this->pendingPreset = null;
        $this->clearImpact();
    }

    public function currentMonth(): void
    {
        $this->month = CarbonImmutable::now($this->crmTimezone())->format('Y-m');
        $this->pendingPreset = null;
        $this->clearImpact();
    }

    public function applyPreset(string $preset): void
    {
        if (! in_array($preset, ['weekdays', 'all', 'even', 'odd', 'clear'], true)) {
            return;
        }

        $this->pendingPreset = $preset;
        $this->clearImpact();
    }

    public function editDate(string $date): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! str_starts_with($date, $this->month)) {
            return;
        }

        $this->selectedDate = $date;
        $this->selectedScheduleDate = $this->scheduleDateForDisplayDate($date);
        $exception = $this->selectedException();
        $this->selectedExceptionId = $exception?->getKey();
        $this->overrideReason = (string) ($exception->reason ?? '');

        if ($exception?->exception_type === ScheduleExceptionType::DayOff) {
            $this->overrideType = ScheduleExceptionType::DayOff->value;
            $this->overrideStart = $this->startTime;
            $this->overrideEnd = $this->endTime;
        } elseif ($exception?->exception_type === ScheduleExceptionType::CustomWindow) {
            $this->overrideType = ScheduleExceptionType::CustomWindow->value;
            $this->overrideStart = substr((string) $exception->start_time, 0, 5);
            $this->overrideEnd = substr((string) $exception->end_time, 0, 5);
        } else {
            $this->overrideType = 'working';
            $this->overrideStart = $this->startTime;
            $this->overrideEnd = $this->endTime;
        }
        $this->clearImpact();
    }

    public function saveSchedule(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $specialist = $this->selectedSpecialist();

        if (! $specialist instanceof Specialist) {
            $this->errorMessage = 'Выберите специалиста.';

            return;
        }

        $impactSource = $this->pendingPreset === null ? 'weekly' : 'monthly';

        try {
            $preset = $this->pendingPreset;
            if ($preset !== null) {
                DB::transaction(function () use ($actor, $specialist, $preset, &$impactSource): void {
                    $impactSource = 'monthly';
                    $monthlyDigest = $this->impactSource === 'monthly'
                        ? $this->impactDigest
                        : $this->monthlyAcknowledgedImpactDigest;
                    $monthlyAcknowledged = $this->monthlyAcknowledgedImpactDigest !== null
                        || ($this->impactSource === 'monthly' && $this->acknowledgeImpact);
                    app(ApplyMonthlySchedulePreset::class)->handle(
                        actor: $actor,
                        specialist: $specialist,
                        month: $this->month,
                        preset: $preset,
                        startTime: $this->startTime,
                        endTime: $this->endTime,
                        breakEnabled: $this->breakEnabled,
                        breakStart: $this->breakStart,
                        breakEnd: $this->breakEnd,
                        acknowledgeImpact: $monthlyAcknowledged,
                        acknowledgedImpactDigest: $monthlyDigest,
                    );
                    if ($monthlyAcknowledged && $monthlyDigest !== null) {
                        $this->monthlyAcknowledgedImpactDigest = $monthlyDigest;
                    }

                    $impactSource = 'weekly';
                    app(SetSpecialistWorkingHours::class)->handle(
                        actor: $actor,
                        specialist: $specialist,
                        definitions: $this->scheduleDefinitions(),
                        acknowledgeImpact: $this->impactSource === 'weekly' && $this->acknowledgeImpact,
                        acknowledgedImpactDigest: $this->impactSource === 'weekly' ? $this->impactDigest : null,
                    );
                });
            } else {
                app(SetSpecialistWorkingHours::class)->handle(
                    actor: $actor,
                    specialist: $specialist,
                    definitions: $this->scheduleDefinitions(),
                    acknowledgeImpact: $this->acknowledgeImpact,
                    acknowledgedImpactDigest: $this->impactDigest,
                );
            }
        } catch (ValidationException $exception) {
            $this->setImpactFromException($exception, $impactSource);

            return;
        } catch (\InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $this->loadScheduleState();
        $this->pendingPreset = null;
        $this->clearImpact();
        Notification::make()->success()->title('График сохранён')->send();
    }

    public function saveOverride(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $specialist = $this->selectedSpecialist();
        $exception = $this->selectedException();

        if (! $specialist instanceof Specialist || $this->selectedDate === null || $this->selectedScheduleDate === null) {
            $this->errorMessage = 'Выберите дату и специалиста.';

            return;
        }

        try {
            if ($this->overrideType === 'working') {
                if ($exception instanceof ScheduleException) {
                    app(DeleteScheduleException::class)->handle(
                        actor: $actor,
                        exception: $exception,
                        acknowledgeImpact: $this->acknowledgeImpact,
                        acknowledgedImpactDigest: $this->impactDigest,
                    );
                }
            } else {
                $attributes = [
                    'exception_date' => $this->selectedScheduleDate,
                    'exception_type' => $this->overrideType,
                    'start_time' => $this->overrideType === ScheduleExceptionType::CustomWindow->value ? $this->overrideStart : null,
                    'end_time' => $this->overrideType === ScheduleExceptionType::CustomWindow->value ? $this->overrideEnd : null,
                    'reason' => trim($this->overrideReason) === '' ? null : trim($this->overrideReason),
                ];

                if ($exception instanceof ScheduleException) {
                    app(UpdateScheduleException::class)->handle(
                        actor: $actor,
                        exception: $exception,
                        attributes: $attributes,
                        acknowledgeImpact: $this->acknowledgeImpact,
                        acknowledgedImpactDigest: $this->impactDigest,
                    );
                } else {
                    app(CreateScheduleException::class)->handle(
                        actor: $actor,
                        specialist: $specialist,
                        attributes: $attributes,
                        acknowledgeImpact: $this->acknowledgeImpact,
                        acknowledgedImpactDigest: $this->impactDigest,
                    );
                }
            }
        } catch (ValidationException $exception) {
            $this->setImpactFromException($exception);

            return;
        } catch (\InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $this->editDate($this->selectedDate);
        $this->clearImpact();
        Notification::make()->success()->title('Изменение сохранено')->send();
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
                displayTimezone: $this->crmTimezone(),
            )
            : [];
    }

    /** @return list<array{date: string, weekday: int, is_working: bool, exception_type: string|null, intervals: list<array{start: string, end: string, start_minutes: int, end_minutes: int}>}|null> */
    public function getMonthCellsProperty(): array
    {
        $monthStart = $this->monthDate()->startOfMonth();
        $days = $monthStart->daysInMonth;
        $cells = array_fill(0, $monthStart->dayOfWeekIso - 1, null);

        for ($day = 1; $day <= $days; $day++) {
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
            ->pluck('display_name', 'id')
            ->all();
    }

    public function monthLabel(): string
    {
        $months = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
            7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        return $months[$this->monthDate()->month].' '.$this->monthDate()->year;
    }

    public function isToday(string $date): bool
    {
        return $date === CarbonImmutable::now($this->crmTimezone())->toDateString();
    }

    public function crmTimezone(): string
    {
        return IanaTimezone::from(app(OrganizationContext::class)->defaultTimezone())->value;
    }

    public function specialistScheduleTimezone(): string
    {
        $specialist = $this->selectedSpecialist();
        $timezone = $specialist === null ? $this->crmTimezone() : ($specialist->timezone ?? $this->crmTimezone());

        return IanaTimezone::from($timezone)->value;
    }

    public function pendingPresetLabel(): ?string
    {
        return match ($this->pendingPreset) {
            'weekdays' => 'Будни',
            'all' => 'Все даты',
            'even' => 'Чётные даты',
            'odd' => 'Нечётные даты',
            'clear' => 'Все даты — выходные',
            default => null,
        };
    }

    public function isPresetWorkingDate(string $date): bool
    {
        if ($this->pendingPreset === null) {
            return false;
        }

        $day = (int) substr($date, -2);
        $weekday = CarbonImmutable::parse($date, $this->crmTimezone())->dayOfWeekIso;

        return match ($this->pendingPreset) {
            'weekdays' => $weekday <= 5,
            'all' => true,
            'even' => $day % 2 === 0,
            'odd' => $day % 2 === 1,
            'clear' => false,
            default => false,
        };
    }

    public function hasPendingImpact(): bool
    {
        return $this->impactBookings !== [];
    }

    private function loadScheduleState(): void
    {
        $specialist = $this->selectedSpecialist();
        $hours = $specialist instanceof Specialist
            ? SpecialistWorkingHour::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->where('specialist_id', $specialist->getKey())
                ->where('is_active', true)
                ->orderBy('weekday')
                ->orderBy('start_time')
                ->get()
            : collect();
        $this->selectedWeekdays = array_values($hours->pluck('weekday')->map(fn (mixed $weekday): int => (int) $weekday)->unique()->values()->all());
        $first = $hours->first();
        $last = $hours->sortByDesc('end_time')->first();
        $this->startTime = $first === null ? '09:00' : substr((string) $first->start_time, 0, 5);
        $this->endTime = $last === null ? '19:00' : substr((string) $last->end_time, 0, 5);
        $groupedHours = [];
        foreach ($hours as $hour) {
            $groupedHours[(int) $hour->weekday][] = $hour;
        }
        $breakIntervals = $this->uniformBreakIntervals($groupedHours);
        $this->breakEnabled = is_array($breakIntervals);
        if ($breakIntervals !== null) {
            $this->breakStart = substr((string) $breakIntervals[0]->end_time, 0, 5);
            $this->breakEnd = substr((string) $breakIntervals[1]->start_time, 0, 5);
        }
    }

    /** @return list<array{weekday: int, start_time: string, end_time: string}> */
    private function scheduleDefinitions(): array
    {
        $definitions = [];
        foreach (array_values(array_unique(array_map('intval', $this->selectedWeekdays))) as $weekday) {
            if ($this->breakEnabled) {
                $first = WallClockInterval::from($this->startTime, $this->breakStart);
                $second = WallClockInterval::from($this->breakEnd, $this->endTime);
                $definitions[] = ['weekday' => $weekday, 'start_time' => $first->start, 'end_time' => $first->end];
                $definitions[] = ['weekday' => $weekday, 'start_time' => $second->start, 'end_time' => $second->end];

                continue;
            }

            $interval = WallClockInterval::from($this->startTime, $this->endTime);
            $definitions[] = ['weekday' => $weekday, 'start_time' => $interval->start, 'end_time' => $interval->end];
        }

        return $definitions;
    }

    private function selectedSpecialist(): ?Specialist
    {
        return $this->specialistId === null
            ? null
            : Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->whereKey($this->specialistId)
                ->first();
    }

    private function selectedException(): ?ScheduleException
    {
        if ($this->specialistId === null || $this->selectedDate === null) {
            return null;
        }

        return ScheduleException::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('specialist_id', $this->specialistId)
            ->where('exception_date', $this->selectedScheduleDate)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<int, list<SpecialistWorkingHour>>  $groupedHours
     * @return array{SpecialistWorkingHour, SpecialistWorkingHour}|null
     */
    private function uniformBreakIntervals(array $groupedHours): ?array
    {
        $reference = reset($groupedHours);
        if (! is_array($reference) || count($reference) !== 2) {
            return null;
        }

        $referencePairs = $this->intervalPairs($reference);
        foreach ($groupedHours as $intervals) {
            if (count($intervals) !== 2 || $this->intervalPairs($intervals) !== $referencePairs) {
                return null;
            }
        }

        usort($reference, static fn (SpecialistWorkingHour $left, SpecialistWorkingHour $right): int => strcmp((string) $left->start_time, (string) $right->start_time));

        return [$reference[0], $reference[1]];
    }

    /**
     * @param  list<SpecialistWorkingHour>  $hours
     * @return list<array{string, string}>
     */
    private function intervalPairs(array $hours): array
    {
        usort($hours, static fn (SpecialistWorkingHour $left, SpecialistWorkingHour $right): int => strcmp((string) $left->start_time, (string) $right->start_time));

        return array_map(
            static fn (SpecialistWorkingHour $hour): array => [
                substr((string) $hour->start_time, 0, 5),
                substr((string) $hour->end_time, 0, 5),
            ],
            $hours,
        );
    }

    private function currentViewerSpecialist(): ?Specialist
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->where('staff_user_id', $actor->getKey())
                ->first()
            : null;
    }

    private function monthDate(): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->month.'-01', new \DateTimeZone($this->crmTimezone()));

        if (! $date instanceof CarbonImmutable || $date->format('Y-m') !== $this->month) {
            return CarbonImmutable::now($this->crmTimezone())->startOfMonth();
        }

        return $date;
    }

    private function scheduleDateForDisplayDate(string $date): string
    {
        $displayDate = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i',
            $date.' 12:00',
            new \DateTimeZone($this->crmTimezone()),
        );

        if (! $displayDate instanceof CarbonImmutable) {
            return $date;
        }

        return $displayDate->setTimezone($this->specialistScheduleTimezone())->toDateString();
    }

    private function clearImpact(): void
    {
        $this->impactDigest = null;
        $this->impactBookings = [];
        $this->acknowledgeImpact = false;
        $this->impactSource = null;
        $this->monthlyAcknowledgedImpactDigest = null;
        $this->errorMessage = '';
    }

    private function setImpactFromException(ValidationException $exception, ?string $source = null): void
    {
        $preview = ScheduleImpactPreview::stateFromValidationException($exception);
        $this->impactDigest = $preview['impact_digest'] ?? null;
        $this->impactBookings = $preview['schedule_impact_bookings'] ?? [];
        $this->acknowledgeImpact = false;
        $this->impactSource = $source;
        $this->errorMessage = $exception->errors()['schedule_impact'][0] ?? $exception->getMessage();
    }
}
