<?php

namespace App\Modules\Finance\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;

final readonly class GatewayInitiationRequest
{
    public function __construct(
        public int $organizationId,
        public int $obligationId,
        public int $amountMinor,
        public CurrencyCode $currency,
        public string $idempotencyKey,
        public ?string $buyerEmail = null,
        public ?string $providerOfferId = null,
        public ?string $successfulReturnUrl = null,
        public ?string $failureReturnUrl = null,
        public ?string $cancelReturnUrl = null,
    ) {}
}
