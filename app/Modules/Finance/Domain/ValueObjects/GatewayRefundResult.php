<?php

namespace App\Modules\Finance\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;

final readonly class GatewayRefundResult
{
    public function __construct(
        public string $gateway,
        public string $providerEventId,
        public string $providerReference,
        public int $amountMinor,
        public CurrencyCode $currency,
    ) {}
}
