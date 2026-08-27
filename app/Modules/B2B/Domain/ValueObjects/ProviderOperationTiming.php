<?php

namespace App\Modules\B2B\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ProviderOperationTiming
{
    public const int MIN_OPERATION_DEADLINE_SECONDS = 1;

    public const int MAX_OPERATION_DEADLINE_SECONDS = 3600;

    public const int MIN_LEASE_MARGIN_SECONDS = 1;

    public const int MAX_LEASE_MARGIN_SECONDS = 300;

    public const int MIN_REQUEST_SAFETY_SECONDS = 1;

    public const int MAX_REQUEST_SAFETY_SECONDS = 60;

    public function __construct(
        public int $operationDeadlineSeconds,
        public int $leaseMarginSeconds,
        public int $requestSafetySeconds,
    ) {
        if ($operationDeadlineSeconds < self::MIN_OPERATION_DEADLINE_SECONDS
            || $operationDeadlineSeconds > self::MAX_OPERATION_DEADLINE_SECONDS
            || $leaseMarginSeconds < self::MIN_LEASE_MARGIN_SECONDS
            || $leaseMarginSeconds > self::MAX_LEASE_MARGIN_SECONDS
            || $requestSafetySeconds < self::MIN_REQUEST_SAFETY_SECONDS
            || $requestSafetySeconds > self::MAX_REQUEST_SAFETY_SECONDS
            || $operationDeadlineSeconds <= $requestSafetySeconds) {
            throw new InvalidArgumentException('The B2B provider timing configuration is invalid.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            operationDeadlineSeconds: (int) config('b2b.provider.operation_deadline_seconds', 90),
            leaseMarginSeconds: (int) config('b2b.provider.lease_margin_seconds', 15),
            requestSafetySeconds: (int) config('b2b.provider.request_safety_seconds', 2),
        );
    }

    public function providerDeadlineExpiresAt(CarbonImmutable $claimNow): CarbonImmutable
    {
        return $claimNow->addSeconds($this->operationDeadlineSeconds);
    }

    public function leaseExpiresAt(CarbonImmutable $providerDeadlineExpiresAt): CarbonImmutable
    {
        $leaseExpiresAt = $providerDeadlineExpiresAt->addSeconds($this->leaseMarginSeconds);

        if (! $providerDeadlineExpiresAt->lessThan($leaseExpiresAt)) {
            throw new InvalidArgumentException('The B2B provider lease must outlive its provider deadline.');
        }

        return $leaseExpiresAt;
    }
}
