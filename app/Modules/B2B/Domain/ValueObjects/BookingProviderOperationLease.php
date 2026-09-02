<?php

namespace App\Modules\B2B\Domain\ValueObjects;

use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class BookingProviderOperationLease
{
    public function __construct(
        public int $organizationId,
        public int $bookingId,
        public int $eventId,
        public string $eventProcessingToken,
        public string $leaseToken,
        public int $eventVersion,
        public int $providerSyncVersion,
        public VideoMeetingOperation $operation,
        public CarbonImmutable $providerDeadlineExpiresAt,
        public CarbonImmutable $leaseExpiresAt,
        public int $requestSafetySeconds,
    ) {
        if ($requestSafetySeconds < 1 || ! $providerDeadlineExpiresAt->lessThan($leaseExpiresAt)) {
            throw new InvalidArgumentException('The provider operation lease timing is invalid.');
        }
    }

    public function providerDeadline(): ProviderOperationDeadline
    {
        return ProviderOperationDeadline::fromExpiresAt(
            $this->providerDeadlineExpiresAt,
            $this->requestSafetySeconds,
        );
    }
}
