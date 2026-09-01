<?php

namespace Tests\Unit;

use App\Modules\B2B\Domain\ValueObjects\B2bSalesCallDuration;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class B2bSalesCallDurationTest extends TestCase
{
    public function test_exact_whole_minute_duration_is_returned(): void
    {
        $duration = B2bSalesCallDuration::between(
            new DateTimeImmutable('2026-09-01 10:00:00+05:00'),
            new DateTimeImmutable('2026-09-01 05:40:00+00:00'),
        );

        self::assertSame(40, $duration->minutes);
    }

    public function test_one_minute_duration_is_returned_exactly(): void
    {
        $duration = B2bSalesCallDuration::between(
            new DateTimeImmutable('2026-09-01 10:00:00+05:00'),
            new DateTimeImmutable('2026-09-01 07:01:00+02:00'),
        );

        self::assertSame(1, $duration->minutes);
    }

    #[DataProvider('invalidIntervals')]
    public function test_non_canonical_intervals_are_rejected(string $startsAt, string $endsAt): void
    {
        $this->expectException(InvalidArgumentException::class);

        B2bSalesCallDuration::between(
            new DateTimeImmutable($startsAt),
            new DateTimeImmutable($endsAt),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function invalidIntervals(): array
    {
        return [
            'forty minutes and one second' => ['2026-09-01 15:00:00+00:00', '2026-09-01 15:40:01+00:00'],
            'thirty-nine minutes and fifty-nine seconds' => ['2026-09-01 15:00:00+00:00', '2026-09-01 15:39:59+00:00'],
            'thirty seconds' => ['2026-09-01 15:00:00+00:00', '2026-09-01 15:00:30+00:00'],
            'fifty-nine seconds' => ['2026-09-01 15:00:00+00:00', '2026-09-01 15:00:59+00:00'],
            'start seconds' => ['2026-09-01 15:00:01+00:00', '2026-09-01 16:00:00+00:00'],
            'end seconds' => ['2026-09-01 15:00:00+00:00', '2026-09-01 16:00:01+00:00'],
            'start microseconds' => ['2026-09-01 15:00:00.000001+00:00', '2026-09-01 16:00:00+00:00'],
            'end microseconds' => ['2026-09-01 15:00:00+00:00', '2026-09-01 16:00:00.000001+00:00'],
            'zero duration' => ['2026-09-01 15:00:00+00:00', '2026-09-01 15:00:00+00:00'],
            'negative duration' => ['2026-09-01 15:00:00+00:00', '2026-09-01 14:59:00+00:00'],
        ];
    }
}
