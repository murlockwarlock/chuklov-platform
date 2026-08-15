<?php

namespace App\Modules\Finance\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;

final readonly class GatewaySettlementEvidence
{
    public function __construct(
        public int $organizationId,
        public string $providerEventId,
        public string $providerReference,
        public int $amountMinor,
        public CurrencyCode $currency,
        public string $proof,
    ) {}
}
