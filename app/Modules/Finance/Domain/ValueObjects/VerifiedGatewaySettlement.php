<?php

namespace App\Modules\Finance\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;

final readonly class VerifiedGatewaySettlement
{
    public function __construct(
        public string $providerEventId,
        public string $providerReference,
        public int $amountMinor,
        public CurrencyCode $currency,
    ) {}
}
