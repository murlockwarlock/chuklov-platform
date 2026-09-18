<?php

namespace App\Modules\Finance\Application;

use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use Illuminate\Validation\ValidationException;

final class ResolvePaymentProviderOfferMapping
{
    public function __construct(
        private readonly RecordScenarioEvent $scenarioEvents,
    ) {}

    public function handle(
        int $organizationId,
        string $gateway,
        string $sellableType,
        int $sellableId,
        CurrencyCode $currency,
    ): PaymentProviderOfferMapping {
        $mappings = PaymentProviderOfferMapping::query()
            ->activeFor($organizationId, $gateway, $sellableType, $sellableId, $currency->value)
            ->lockForUpdate()
            ->get();

        if ($mappings->count() !== 1) {
            $reason = $mappings->isEmpty() ? 'missing_offer_mapping' : 'ambiguous_offer_mapping';
            $this->scenarioEvents->paymentInitiationUnavailable(
                organizationId: $organizationId,
                gateway: $gateway,
                reason: $reason,
                occurredAt: now()->toImmutable(),
                sellableType: $sellableType,
                sellableId: $sellableId,
                currency: $currency->value,
            );

            throw ValidationException::withMessages(['payment_provider' => 'Для выбранного товара не настроено ровно одно активное предложение оплаты.']);
        }

        return $mappings->firstOrFail();
    }
}
