<?php

namespace App\Modules\Sessions\Domain\ValueObjects;

final readonly class EncryptedSessionPayload
{
    public function __construct(
        public ?string $encryptedPain,
        public ?string $encryptedTests,
        public ?string $encryptedObservations,
        public ?string $encryptedRootCauseHypothesis,
        public ?string $encryptedProtocol,
        public ?string $encryptedResult,
        public int $keyVersion = 1,
    ) {}
}
