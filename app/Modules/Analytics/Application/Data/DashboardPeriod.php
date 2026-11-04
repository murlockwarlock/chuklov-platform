<?php

namespace App\Modules\Analytics\Application\Data;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final readonly class DashboardPeriod
{
    public const string Today = 'today';

    public const string LastSevenDays = 'last_7_days';

    public const string LastThirtyDays = 'last_30_days';

    public const string LastNinetyDays = 'last_90_days';

    public const string CurrentMonth = 'current_month';

    public const string CurrentYear = 'current_year';

    public const string Custom = 'custom';

    public const string DefaultPreset = self::LastThirtyDays;

    public const int MaximumCustomRangeDays = 366;

    public function __construct(
        public CarbonImmutable $startUtc,
        public CarbonImmutable $endUtc,
        public string $timezone,
        public string $startDate,
        public string $endDate,
        public string $preset,
        public CarbonImmutable $nowUtc,
    ) {}

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Today => 'Сегодня',
            self::LastSevenDays => 'Последние 7 дней',
            self::LastThirtyDays => 'Последние 30 дней',
            self::LastNinetyDays => 'Последние 90 дней',
            self::CurrentMonth => 'Текущий месяц',
            self::CurrentYear => 'Текущий год',
            self::Custom => 'Произвольный период',
        ];
    }

    /** @param array<string, mixed>|null $filters */
    public static function fromFilters(?array $filters, string $timezone, ?CarbonImmutable $nowUtc = null): self
    {
        $now = ($nowUtc ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        $dateTimezone = new DateTimeZone($timezone);
        $preset = is_string($filters['period'] ?? null)
            ? $filters['period']
            : self::DefaultPreset;

        if ($preset === self::Custom) {
            [$start, $end] = self::validatedCustomRange($filters, $dateTimezone);

            return self::make($start, $end, $timezone, self::Custom, $now);
        }

        if (! array_key_exists($preset, self::options()) || $preset === self::Custom) {
            $preset = self::DefaultPreset;
        }

        $today = $now->setTimezone($dateTimezone)->startOfDay();
        $start = match ($preset) {
            self::Today => $today,
            self::LastSevenDays => $today->subDays(6),
            self::LastThirtyDays => $today->subDays(29),
            self::LastNinetyDays => $today->subDays(89),
            self::CurrentMonth => $today->startOfMonth(),
            self::CurrentYear => $today->startOfYear(),
            default => $today->subDays(29),
        };

        return self::make($start, $today, $timezone, $preset, $now);
    }

    public function previousCalendarPeriod(): self
    {
        $dateTimezone = new DateTimeZone($this->timezone);
        $start = $this->startUtc->setTimezone($dateTimezone)->startOfDay();
        $end = $this->endUtc->subSecond()->setTimezone($dateTimezone)->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $previousEnd = $start->subDay();
        $previousStart = $previousEnd->subDays($days - 1);
        $previousNow = $this->nowUtc
            ->setTimezone($dateTimezone)
            ->subDays($days)
            ->setTimezone('UTC');

        return self::make($previousStart, $previousEnd, $this->timezone, self::Custom, $previousNow);
    }

    public function label(): string
    {
        return $this->startDate === $this->endDate
            ? $this->startDate
            : $this->startDate.' — '.$this->endDate;
    }

    private static function make(
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $timezone,
        string $preset,
        CarbonImmutable $nowUtc,
    ): self {
        return new self(
            startUtc: $start->setTimezone('UTC'),
            endUtc: $end->addDay()->setTimezone('UTC'),
            timezone: $timezone,
            startDate: $start->format('Y-m-d'),
            endDate: $end->format('Y-m-d'),
            preset: $preset,
            nowUtc: $nowUtc,
        );
    }

    private static function parseDate(mixed $value, DateTimeZone $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (\Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value
            ? $date
            : null;
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function validatedCustomRange(?array $filters, DateTimeZone $timezone): array
    {
        $start = self::validatedDate(
            $filters['start_date'] ?? null,
            $timezone,
            'start_date',
            'Укажите дату начала произвольного периода.',
        );
        $end = self::validatedDate(
            $filters['end_date'] ?? null,
            $timezone,
            'end_date',
            'Укажите дату окончания произвольного периода.',
        );

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'end_date' => 'Дата окончания не может быть раньше даты начала.',
            ]);
        }
        if ($start->diffInDays($end) + 1 > self::MaximumCustomRangeDays) {
            throw ValidationException::withMessages([
                'end_date' => 'Выберите период не более 366 дней.',
            ]);
        }

        return [$start, $end];
    }

    private static function validatedDate(
        mixed $value,
        DateTimeZone $timezone,
        string $field,
        string $requiredMessage,
    ): CarbonImmutable {
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([$field => $requiredMessage]);
        }

        $date = self::parseDate($value, $timezone);
        if ($date === null) {
            throw ValidationException::withMessages([
                $field => 'Укажите дату в формате ГГГГ-ММ-ДД.',
            ]);
        }

        return $date;
    }
}
