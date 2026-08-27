<?php

namespace Tests\Unit;

use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationTiming;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class B2bProviderTimingTest extends TestCase
{
    public function test_claim_time_deadline_is_consumed_before_the_lease_expires(): void
    {
        $base = CarbonImmutable::create(2026, 8, 27, 10, 0, 0, 'UTC');
        if (! $base instanceof CarbonImmutable) {
            self::fail('The timing test base instant could not be created.');
        }
        $timing = new ProviderOperationTiming(10, 5, 1);
        $providerDeadlineExpiresAt = $timing->providerDeadlineExpiresAt($base);
        $leaseExpiresAt = $timing->leaseExpiresAt($providerDeadlineExpiresAt);

        self::assertTrue($providerDeadlineExpiresAt->equalTo($base->addSeconds(10)));
        self::assertTrue($leaseExpiresAt->equalTo($base->addSeconds(15)));
        self::assertTrue($providerDeadlineExpiresAt->lessThan($leaseExpiresAt));

        try {
            CarbonImmutable::setTestNow($base->addSeconds(6));
            $deadline = new ProviderOperationDeadline($providerDeadlineExpiresAt, 1);
            self::assertEqualsWithDelta(4.0, $deadline->remainingSeconds(), 0.001);
            self::assertTrue($deadline->canStart());
            self::assertTrue($leaseExpiresAt->greaterThan(CarbonImmutable::now('UTC')));

            CarbonImmutable::setTestNow($base->addSeconds(11));
            self::assertFalse($deadline->canStart());
            self::assertNull($deadline->timeoutSeconds(30));
            self::assertTrue($leaseExpiresAt->greaterThan(CarbonImmutable::now('UTC')));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[DataProvider('unsafeTimingProvider')]
    public function test_provider_timing_rejects_unsafe_values(
        int $operationDeadlineSeconds,
        int $leaseMarginSeconds,
        int $requestSafetySeconds,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new ProviderOperationTiming(
            $operationDeadlineSeconds,
            $leaseMarginSeconds,
            $requestSafetySeconds,
        );
    }

    /** @return list<array{int, int, int}> */
    public static function unsafeTimingProvider(): array
    {
        return [
            [10, 5, 10],
            [10, 0, 1],
            [3601, 5, 1],
            [10, 301, 1],
            [10, 5, 61],
        ];
    }
}
