<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Support\BookingLocalDateRange;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\GetScheduleCalendar;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected static ?string $title = 'Журнал записей';

    protected string $view = 'filament.resources.bookings.pages.list-bookings';

    #[Url(as: 'view', history: true)]
    public string $viewMode = 'week';

    #[Url(as: 'week', history: true)]
    public string $weekStart = '';

    #[Url(as: 'specialist_id', history: true, nullable: true)]
    public ?int $selectedSpecialistId = null;

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->when($this->selectedSpecialistId !== null, fn (Builder $query): Builder => $query->where(
                'specialist_id',
                $this->selectedSpecialistId,
            ));
    }

    public function mount(): void
    {
        parent::mount();

        $timezone = $this->journalTimezone();
        $week = $this->parseWeek($this->weekStart, $timezone)
            ?? CarbonImmutable::now($timezone)->startOfWeek(CarbonImmutable::MONDAY);
        $this->weekStart = $week->toDateString();
        $this->viewMode = in_array($this->viewMode, ['week', 'list'], true) ? $this->viewMode : 'week';
        $this->selectedSpecialistId = $this->selectedSpecialistFromUrl()?->getKey()
            ?? $this->currentViewerSpecialist()?->getKey()
            ?? Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->where('is_active', true)
                ->orderBy('display_name')
                ->value('id')
            ?? Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->orderBy('display_name')
                ->value('id');
    }

    public function previousWeek(): void
    {
        $this->weekStart = $this->weekDate()->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->weekStart = $this->weekDate()->addWeek()->toDateString();
    }

    public function today(): void
    {
        $this->weekStart = CarbonImmutable::now($this->journalTimezone())->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['week', 'list'], true) ? $mode : 'week';
    }

    public function updatedSelectedSpecialistId(): void
    {
        if ($this->selectedSpecialist() instanceof Specialist) {
            return;
        }

        $this->selectedSpecialistId = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('is_active', true)
            ->orderBy('display_name')
            ->value('id')
            ?? Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->orderBy('display_name')
                ->value('id');
    }

    /** @return array<string, array{date: string, day_number: int, weekday: string, is_today: bool, is_working: bool, intervals: list<array{start: string, end: string, start_minutes: int, end_minutes: int}>, bookings: list<array<string, mixed>>}> */
    public function getJournalDaysProperty(): array
    {
        $timezone = $this->journalTimezone();
        $start = $this->weekDate();
        $end = $start->addDays(6);
        $weekdayLabels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
        $calendar = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $calendar[$key] = [
                'date' => $key,
                'day_number' => $date->day,
                'weekday' => $weekdayLabels[$date->dayOfWeekIso],
                'is_today' => $key === CarbonImmutable::now($timezone)->toDateString(),
                'is_working' => false,
                'intervals' => [],
                'bookings' => [],
            ];
        }

        $specialist = $this->selectedSpecialist();
        if ($specialist instanceof Specialist) {
            $schedule = app(GetScheduleCalendar::class)->forSpecialist(
                specialist: $specialist,
                dateFrom: $start->toDateString(),
                dateTo: $end->toDateString(),
                displayTimezone: $timezone,
            );
            foreach ($schedule as $date => $day) {
                if (isset($calendar[$date])) {
                    $calendar[$date]['is_working'] = $day['is_working'];
                    $calendar[$date]['intervals'] = $day['intervals'];
                }
            }
        }

        $bookings = Booking::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->when($this->selectedSpecialistId !== null, fn (Builder $query): Builder => $query->where('specialist_id', $this->selectedSpecialistId))
            ->where('starts_at', '>=', $start->startOfDay()->setTimezone('UTC'))
            ->where('starts_at', '<', $end->addDay()->startOfDay()->setTimezone('UTC'))
            ->with(['client', 'service', 'specialist'])
            ->orderBy('starts_at')
            ->get();

        foreach ($bookings as $booking) {
            $localStart = $booking->startsAtUtc()->setTimezone($timezone);
            $localEnd = $booking->blockingEndsAtUtc()->setTimezone($timezone);
            $date = $localStart->toDateString();
            if (! isset($calendar[$date])) {
                continue;
            }

            $calendar[$date]['bookings'][] = $this->bookingProjection($booking, $localStart, $localEnd);
        }

        return $calendar;
    }

    /** @return array{start: int, end: int, rows: int, labels: list<array{minutes: int, label: string}>} */
    public function getJournalGridProperty(): array
    {
        $minutes = [8 * 60, 20 * 60];
        foreach ($this->getJournalDaysProperty() as $day) {
            foreach ($day['intervals'] as $interval) {
                $minutes[] = $interval['start_minutes'];
                $minutes[] = $interval['end_minutes'];
            }
            foreach ($day['bookings'] as $booking) {
                $minutes[] = $booking['start_minutes'];
                $minutes[] = $booking['end_minutes'];
            }
        }

        $start = max(0, intdiv(min($minutes), 30) * 30);
        $end = min(24 * 60, (int) ceil(max($minutes) / 30) * 30);
        $end = max($start + 60, $end);
        $labels = [];
        for ($minute = $start; $minute <= $end; $minute += 60) {
            $labels[] = ['minutes' => $minute, 'label' => sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60)];
        }

        return [
            'start' => $start,
            'end' => $end,
            'rows' => max(1, intdiv($end - $start, 30)),
            'labels' => $labels,
        ];
    }

    public function bookingCreationUrl(string $date, string $time): string
    {
        $parameters = [
            'starts_at' => $date.' '.$time,
            'return_to_journal' => '1',
            'week' => $this->weekStart,
            'view' => $this->viewMode,
        ];
        if ($this->selectedSpecialistId !== null) {
            $parameters['specialist_id'] = (string) $this->selectedSpecialistId;
        }

        return BookingResource::getUrl('create').'?'.http_build_query($parameters);
    }

    public function newBookingUrl(): string
    {
        $parameters = [
            'return_to_journal' => '1',
            'week' => $this->weekStart,
            'view' => $this->viewMode,
        ];
        if ($this->selectedSpecialistId !== null) {
            $parameters['specialist_id'] = (string) $this->selectedSpecialistId;
        }

        return BookingResource::getUrl('create').'?'.http_build_query($parameters);
    }

    public function canCreateBooking(): bool
    {
        $specialist = $this->selectedSpecialist();

        return $specialist instanceof Specialist && $specialist->is_active;
    }

    /** @param array{start_minutes: int, end_minutes: int} $booking */
    public function bookingStyle(array $booking): string
    {
        $grid = $this->getJournalGridProperty();
        $top = max(0, $booking['start_minutes'] - $grid['start']) * (44 / 30);
        $height = max(36, ($booking['end_minutes'] - $booking['start_minutes']) * (44 / 30));

        return 'top: '.$top.'px; height: '.$height.'px;';
    }

    public function weekLabel(): string
    {
        $months = [1 => 'янв.', 2 => 'февр.', 3 => 'мар.', 4 => 'апр.', 5 => 'май', 6 => 'июн.', 7 => 'июл.', 8 => 'авг.', 9 => 'сент.', 10 => 'окт.', 11 => 'нояб.', 12 => 'дек.'];
        $start = $this->weekDate();
        $end = $start->addDays(6);

        return $start->day.' '.$months[$start->month].' — '.$end->day.' '.$months[$end->month];
    }

    /** @return array<int, string> */
    public function getSpecialistOptionsProperty(): array
    {
        return Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'is_active'])
            ->mapWithKeys(fn (Specialist $specialist): array => [
                $specialist->getKey() => $specialist->display_name.($specialist->is_active ? '' : ' (неактивен)'),
            ])
            ->all();
    }

    public function journalTimezone(): string
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(ResolveSpecialistViewerTimezone::class)->forUser($actor)
            : app(OrganizationContext::class)->defaultTimezone();
    }

    public function journalTimezoneLabel(): string
    {
        $timezone = $this->journalTimezone();

        return TimezoneOptions::label($timezone).' ('.$timezone.')';
    }

    public static function statusClass(BookingStatus|string $status): string
    {
        $status = $status instanceof BookingStatus ? $status : BookingStatus::tryFrom($status);

        return match ($status) {
            BookingStatus::Confirmed => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-100',
            BookingStatus::Requested, BookingStatus::PendingReview => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100',
            BookingStatus::Cancelled, BookingStatus::Rejected, BookingStatus::NoShow => 'border-gray-200 bg-gray-100 text-gray-600 dark:border-gray-800 dark:bg-gray-800 dark:text-gray-400',
            BookingStatus::Completed => 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/40 dark:text-sky-100',
            default => 'border-gray-200 bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200',
        };
    }

    public static function statusLabel(BookingStatus|string $status): string
    {
        $status = $status instanceof BookingStatus ? $status : BookingStatus::tryFrom($status);

        return match ($status) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'На рассмотрении',
            BookingStatus::Confirmed => 'Подтверждена',
            BookingStatus::Rejected => 'Отклонена',
            BookingStatus::Cancelled => 'Отменена',
            BookingStatus::Completed => 'Завершена',
            BookingStatus::NoShow => 'Не состоялась',
            default => 'Без статуса',
        };
    }

    public static function formatLabel(VisitFormat|string $format): string
    {
        $format = $format instanceof VisitFormat ? $format : VisitFormat::tryFrom($format);

        return match ($format) {
            VisitFormat::Office => 'В клинике',
            VisitFormat::HomeVisit => 'Выезд',
            VisitFormat::Online => 'Онлайн',
            default => 'Визит',
        };
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        $actor = auth()->user();
        $timezone = $actor instanceof User
            ? app(ResolveSpecialistViewerTimezone::class)->forUser($actor)
            : app(OrganizationContext::class)->defaultTimezone();

        return [
            'all' => Tab::make('Все'),
            'today' => Tab::make('Сегодня')
                ->modifyQueryUsing(function (Builder $query) use ($timezone): Builder {
                    $today = CarbonImmutable::now($timezone)->toDateString();

                    return BookingLocalDateRange::apply($query, $today, $today, $timezone);
                }),
            'upcoming' => Tab::make('Предстоящие')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('starts_at', '>=', CarbonImmutable::now('UTC'))
                    ->whereNotIn('status', BookingStatus::terminalValues())),
            'pending_confirmation' => Tab::make('Ожидают подтверждения')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', BookingStatus::Requested->value)),
        ];
    }

    /** @return array{id: int, client: string, service: string, start_time: string, end_time: string, time_range: string, status: string, status_class: string, format: string, is_online: bool, start_minutes: int, end_minutes: int, url: string} */
    private function bookingProjection(Booking $booking, CarbonImmutable $localStart, CarbonImmutable $localEnd): array
    {
        $startMinutes = ((int) $localStart->format('H')) * 60 + (int) $localStart->format('i');
        $endMinutes = ((int) $localEnd->format('H')) * 60 + (int) $localEnd->format('i');
        $status = $booking->status;
        $format = $booking->visit_format;
        $clientName = trim((string) ($booking->client->full_name ?? ''));
        $serviceName = trim((string) ($booking->service->name ?? ''));

        return [
            'id' => $booking->getKey(),
            'client' => $clientName !== '' ? $clientName : 'Клиент',
            'service' => $serviceName !== '' ? $serviceName : 'Услуга',
            'start_time' => $localStart->format('H:i'),
            'end_time' => $localEnd->format('H:i'),
            'time_range' => $localStart->format('H:i').'–'.$localEnd->format('H:i'),
            'status' => self::statusLabel($status),
            'status_class' => self::statusClass($status),
            'format' => self::formatLabel($format),
            'is_online' => $format === VisitFormat::Online,
            'start_minutes' => $startMinutes,
            'end_minutes' => max($startMinutes + 30, $endMinutes),
            'url' => BookingResource::getUrl('view', ['record' => $booking]).'?'.http_build_query([
                'return_to_journal' => '1',
                'specialist_id' => $this->selectedSpecialistId,
                'week' => $this->weekStart,
                'view' => $this->viewMode,
            ]),
        ];
    }

    private function weekDate(): CarbonImmutable
    {
        $date = $this->parseWeek($this->weekStart, $this->journalTimezone());

        return $date instanceof CarbonImmutable
            ? $date
            : CarbonImmutable::now($this->journalTimezone())->startOfWeek(CarbonImmutable::MONDAY);
    }

    private function selectedSpecialist(): ?Specialist
    {
        return $this->selectedSpecialistId === null
            ? null
            : Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->whereKey($this->selectedSpecialistId)
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

    private function selectedSpecialistFromUrl(): ?Specialist
    {
        $specialistId = $this->selectedSpecialistId;

        return $specialistId !== null
            ? Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->whereKey($specialistId)
                ->first()
            : null;
    }

    private function parseWeek(string $value, string $timezone): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value
            ? $date
            : null;
    }
}
