<?php

namespace Tests\Unit\Analytics;

use App\Modules\Analytics\Application\Data\DashboardPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DashboardPeriodTest extends TestCase
{
    public function test_today_uses_organization_wall_clock_boundaries_across_dst(): void
    {
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Today],
            'America/New_York',
            CarbonImmutable::parse('2026-03-08 12:00:00', 'UTC'),
        );

        self::assertSame('2026-03-08T05:00:00+00:00', $period->startUtc->toIso8601String());
        self::assertSame('2026-03-09T04:00:00+00:00', $period->endUtc->toIso8601String());
        self::assertSame('2026-03-08', $period->startDate);
        self::assertSame('2026-03-08', $period->endDate);
    }

    public function test_custom_period_is_half_open_and_bounded(): void
    {
        $period = DashboardPeriod::fromFilters(
            [
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
            ],
            'Asia/Almaty',
            CarbonImmutable::parse('2026-02-15 12:00:00', 'UTC'),
        );

        self::assertSame('2025-12-31T19:00:00+00:00', $period->startUtc->toIso8601String());
        self::assertSame('2026-01-31T19:00:00+00:00', $period->endUtc->toIso8601String());

        try {
            DashboardPeriod::fromFilters(
                [
                    'period' => DashboardPeriod::Custom,
                    'start_date' => '2024-01-01',
                    'end_date' => '2026-01-01',
                ],
                'Asia/Almaty',
                CarbonImmutable::parse('2026-02-15 12:00:00', 'UTC'),
            );
            self::fail('An overlong custom range was replaced by the default period.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('end_date', $exception->errors());
        }
    }

    public function test_custom_period_validation_rejects_missing_malformed_reversed_and_overlong_ranges(): void
    {
        $cases = [
            [['start_date' => null, 'end_date' => '2026-10-01'], 'start_date'],
            [['start_date' => '2026-02-30', 'end_date' => '2026-03-01'], 'start_date'],
            [['start_date' => '2026-10-02', 'end_date' => '2026-10-01'], 'end_date'],
            [['start_date' => '2026-01-01', 'end_date' => '2027-01-02'], 'end_date'],
        ];

        foreach ($cases as [$range, $field]) {
            try {
                DashboardPeriod::fromFilters(
                    ['period' => DashboardPeriod::Custom, ...$range],
                    'UTC',
                    CarbonImmutable::parse('2026-10-31 12:00:00', 'UTC'),
                );
                self::fail('Invalid custom range was accepted.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_exact_366_day_custom_range_is_accepted(): void
    {
        $period = DashboardPeriod::fromFilters([
            'period' => DashboardPeriod::Custom,
            'start_date' => '2026-01-01',
            'end_date' => '2027-01-01',
        ], 'UTC');

        self::assertSame('2026-01-01', $period->startDate);
        self::assertSame('2027-01-01', $period->endDate);
    }

    public function test_previous_period_uses_local_calendar_days_across_spring_and_fall_dst(): void
    {
        $spring = DashboardPeriod::fromFilters([
            'period' => DashboardPeriod::Custom,
            'start_date' => '2026-03-08',
            'end_date' => '2026-03-14',
        ], 'America/New_York')->previousCalendarPeriod();

        self::assertSame('2026-03-01', $spring->startDate);
        self::assertSame('2026-03-07', $spring->endDate);
        self::assertSame('2026-03-01T05:00:00+00:00', $spring->startUtc->toIso8601String());
        self::assertSame('2026-03-08T05:00:00+00:00', $spring->endUtc->toIso8601String());

        $fall = DashboardPeriod::fromFilters([
            'period' => DashboardPeriod::Custom,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-07',
        ], 'America/New_York')->previousCalendarPeriod();

        self::assertSame('2026-10-25', $fall->startDate);
        self::assertSame('2026-10-31', $fall->endDate);
        self::assertSame('2026-10-25T04:00:00+00:00', $fall->startUtc->toIso8601String());
        self::assertSame('2026-11-01T04:00:00+00:00', $fall->endUtc->toIso8601String());
    }

    public function test_previous_period_keeps_today_seven_day_custom_and_non_dst_calendar_boundaries(): void
    {
        $now = CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC');
        $today = DashboardPeriod::fromFilters(['period' => DashboardPeriod::Today], 'Europe/Berlin', $now)->previousCalendarPeriod();
        $sevenDays = DashboardPeriod::fromFilters(['period' => DashboardPeriod::LastSevenDays], 'Europe/Berlin', $now)->previousCalendarPeriod();
        $custom = DashboardPeriod::fromFilters([
            'period' => DashboardPeriod::Custom,
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
        ], 'UTC', $now)->previousCalendarPeriod();

        self::assertSame(['2026-07-14', '2026-07-14'], [$today->startDate, $today->endDate]);
        self::assertSame(['2026-07-02', '2026-07-08'], [$sevenDays->startDate, $sevenDays->endDate]);
        self::assertSame(['2026-07-07', '2026-07-09'], [$custom->startDate, $custom->endDate]);

        $nonDst = DashboardPeriod::fromFilters([
            'period' => DashboardPeriod::Custom,
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-12',
        ], 'UTC', $now)->previousCalendarPeriod();

        self::assertSame('2026-02-07T00:00:00+00:00', $nonDst->startUtc->toIso8601String());
        self::assertSame('2026-02-10T00:00:00+00:00', $nonDst->endUtc->toIso8601String());
    }

    public function test_current_month_and_year_presets_use_organization_timezone_boundaries(): void
    {
        $now = CarbonImmutable::parse('2026-10-28 20:15:00', 'UTC');

        $month = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::CurrentMonth],
            'Asia/Almaty',
            $now,
        );
        $year = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::CurrentYear],
            'Asia/Almaty',
            $now,
        );

        self::assertSame('2026-10-01', $month->startDate);
        self::assertSame('2026-10-29', $month->endDate);
        self::assertSame('2026-01-01', $year->startDate);
        self::assertSame('2026-10-29', $year->endDate);
        self::assertSame('2026-10-01 00:00:00', $month->startUtc->setTimezone('Asia/Almaty')->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-01 00:00:00', $year->startUtc->setTimezone('Asia/Almaty')->format('Y-m-d H:i:s'));
    }
}
