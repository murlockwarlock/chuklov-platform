<?php

namespace App\Modules\Finance\Domain\ValueObjects;

final readonly class GatewayInitiationResult
{
    public function __construct(
        public string $gateway,
        public string $providerReference,
    ) {}
}
