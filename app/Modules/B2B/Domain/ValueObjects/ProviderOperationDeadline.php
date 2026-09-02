<?php

namespace App\Modules\B2B\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ProviderOperationDeadline
{
    public function __construct(
        public CarbonImmutable $expiresAt,
        public int $safetySeconds = 2,
    ) {
        if ($safetySeconds < 1) {
            throw new InvalidArgumentException('The provider operation safety window is invalid.');
        }
    }

    public static function fromNow(int $seconds, ?int $safetySeconds = null): self
    {
        return new self(
            expiresAt: CarbonImmutable::now('UTC')->addSeconds($seconds),
            safetySeconds: $safetySeconds ?? (int) config('b2b.provider.request_safety_seconds', 2),
        );
    }

    public static function fromExpiresAt(CarbonImmutable $expiresAt, ?int $safetySeconds = null): self
    {
        return new self(
            expiresAt: $expiresAt,
            safetySeconds: $safetySeconds ?? (int) config('b2b.provider.request_safety_seconds', 2),
        );
    }

    public function remainingSeconds(): float
    {
        $remainingMicroseconds = CarbonImmutable::now('UTC')->diffInMicroseconds($this->expiresAt, false);

        return max(0, $remainingMicroseconds / 1_000_000);
    }

    public function canStart(int $minimumSeconds = 1): bool
    {
        return $this->remainingSeconds() > $this->safetySeconds + $minimumSeconds;
    }

    public function timeoutSeconds(int $configuredTimeout): ?int
    {
        $remaining = $this->remainingSeconds();
        $available = (int) floor($remaining - $this->safetySeconds);

        if ($available < 1) {
            return null;
        }

        return max(1, min($configuredTimeout, $available));
    }
}
