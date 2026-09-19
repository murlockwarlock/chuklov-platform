<?php

namespace App\Modules\Finance\Infrastructure\Lava;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventType;

final readonly class LavaWebhookEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventType,
        public PaymentGatewayEventType $normalizedType,
        public string $providerEventKey,
        public ?string $providerEventId,
        public ?string $providerReference,
        public ?int $amountMinor,
        public ?CurrencyCode $currency,
        public bool $canAutomaticLink,
        public array $payload,
    ) {}
}
