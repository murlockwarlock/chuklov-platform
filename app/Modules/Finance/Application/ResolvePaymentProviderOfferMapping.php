<?php

namespace App\Modules\Finance\Application;

use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use Illuminate\Validation\ValidationException;

final class ResolvePaymentProviderOfferMapping
{
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
            throw ValidationException::withMessages(['payment_provider' => 'Для выбранного товара не настроено ровно одно активное предложение оплаты.']);
        }

        return $mappings->firstOrFail();
    }
}
