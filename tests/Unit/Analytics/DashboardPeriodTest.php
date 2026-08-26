<?php

namespace Tests\Unit\Analytics;

use App\Modules\Analytics\Application\Data\DashboardPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

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

        $fallback = DashboardPeriod::fromFilters(
            [
                'period' => DashboardPeriod::Custom,
                'start_date' => '2024-01-01',
                'end_date' => '2026-01-01',
            ],
            'Asia/Almaty',
            CarbonImmutable::parse('2026-02-15 12:00:00', 'UTC'),
        );

        self::assertSame(DashboardPeriod::DefaultPreset, $fallback->preset);
        self::assertSame('2026-01-17', $fallback->startDate);
        self::assertSame('2026-02-15', $fallback->endDate);
    }
}
