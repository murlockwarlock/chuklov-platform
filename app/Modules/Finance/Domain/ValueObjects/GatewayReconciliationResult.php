<?php

namespace App\Modules\Finance\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;

final readonly class GatewayReconciliationResult
{
    public function __construct(
        public string $providerReference,
        public string $status,
        public int $amountMinor,
        public CurrencyCode $currency,
    ) {}
}
