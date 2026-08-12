<?php

namespace Tests\Unit;

use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Services\SlotCalculator;
use App\Modules\Scheduling\Domain\ValueObjects\InstantInterval;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SchedulingDomainTest extends TestCase
{
    public function test_wall_clock_intervals_require_ordered_local_times(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WallClockInterval::from('17:00', '09:00');
    }

    public function test_slot_calculation_consumes_duration_and_buffer_and_excludes_unavailable_intervals(): void
    {
        $date = LocalDate::from('2026-08-17');
        $now = CarbonImmutable::create(2026, 8, 16, 12, 0, 0, new DateTimeZone('UTC'));
        $unavailable = [
            InstantInterval::from(
                CarbonImmutable::create(2026, 8, 17, 10, 30, 0, new DateTimeZone('UTC')),
                CarbonImmutable::create(2026, 8, 17, 12, 0, 0, new DateTimeZone('UTC')),
            ),
        ];

        $slots = (new SlotCalculator)->calculate(
            date: $date,
            scheduleTimezone: 'UTC',
            workingIntervals: [WallClockInterval::from('09:00', '15:00')],
            customIntervals: [],
            dayOff: false,
            unavailableIntervals: $unavailable,
            bookingIntervals: [],
            durationMinutes: 60,
            bufferMinutes: 30,
            leadTimeMinutes: 0,
            now: $now,
            format: VisitFormat::Office,
            displayTimezone: 'UTC',
        );

        self::assertSame(['09:00', '12:00', '13:30'], array_map(
            fn ($slot): string => $slot->startsAt->setTimezone('UTC')->format('H:i'),
            $slots,
        ));
        self::assertSame('10:30', $slots[0]->blockingEndsAt->format('H:i'));
    }

    public function test_dst_conversion_uses_iana_timezone_rules(): void
    {
        $slots = (new SlotCalculator)->calculate(
            date: LocalDate::from('2026-03-29'),
            scheduleTimezone: 'Europe/Berlin',
            workingIntervals: [WallClockInterval::from('09:00', '11:00')],
            customIntervals: [],
            dayOff: false,
            unavailableIntervals: [],
            bookingIntervals: [],
            durationMinutes: 60,
            bufferMinutes: 0,
            leadTimeMinutes: 0,
            now: CarbonImmutable::create(2026, 3, 28, 12, 0, 0, new DateTimeZone('UTC')),
            format: VisitFormat::Online,
            displayTimezone: 'Asia/Almaty',
        );

        self::assertCount(2, $slots);
        self::assertSame('2026-03-29T07:00:00+00:00', $slots[0]->startsAt->toIso8601String());
        self::assertSame('2026-03-29T12:00:00+05:00', $slots[0]->toArray()['displayStartsAt']);
    }

    public function test_lead_time_removes_candidates_before_the_configured_instant(): void
    {
        $slots = (new SlotCalculator)->calculate(
            date: LocalDate::from('2026-08-17'),
            scheduleTimezone: 'UTC',
            workingIntervals: [WallClockInterval::from('09:00', '13:00')],
            customIntervals: [],
            dayOff: false,
            unavailableIntervals: [],
            bookingIntervals: [],
            durationMinutes: 60,
            bufferMinutes: 0,
            leadTimeMinutes: 120,
            now: CarbonImmutable::create(2026, 8, 17, 9, 0, 0, new DateTimeZone('UTC')),
            format: VisitFormat::Office,
            displayTimezone: 'UTC',
        );

        self::assertSame(['11:00', '12:00'], array_map(
            fn ($slot): string => $slot->startsAt->format('H:i'),
            $slots,
        ));
    }
}
