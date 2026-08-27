<?php

namespace App\Modules\B2B\Domain\ValueObjects;

use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;

final readonly class ProviderOperationLease
{
    public function __construct(
        public int $organizationId,
        public int $salesCallId,
        public int $eventId,
        public string $eventProcessingToken,
        public string $leaseToken,
        public int $eventVersion,
        public int $providerSyncVersion,
        public VideoMeetingOperation $operation,
    ) {}
}
