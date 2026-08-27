<?php

namespace App\Modules\Analytics\Application\Data;

use Carbon\CarbonImmutable;
use DateTimeZone;

final readonly class DashboardPeriod
{
    public const string Today = 'today';

    public const string LastSevenDays = 'last_7_days';

    public const string LastThirtyDays = 'last_30_days';

    public const string LastNinetyDays = 'last_90_days';

    public const string Custom = 'custom';

    public const string DefaultPreset = self::LastThirtyDays;

    private const int MaximumCustomRangeDays = 366;

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
            $start = self::parseDate($filters['start_date'] ?? null, $dateTimezone);
            $end = self::parseDate($filters['end_date'] ?? null, $dateTimezone);

            if ($start !== null && $end !== null && $start->lessThanOrEqualTo($end)
                && $start->diffInDays($end) + 1 <= self::MaximumCustomRangeDays) {
                return self::make($start, $end, $timezone, self::Custom, $now);
            }

            $preset = self::DefaultPreset;
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
            default => $today->subDays(29),
        };

        return self::make($start, $today, $timezone, $preset, $now);
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
}
