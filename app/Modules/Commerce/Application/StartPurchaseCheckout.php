<?php

namespace App\Modules\Commerce\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\CreateGatewayPaymentAttempt;
use App\Modules\Finance\Application\ResolvePaymentProviderOfferMapping;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Application\ServicePriceResolver;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class StartPurchaseCheckout
{
    public function __construct(
        private readonly CreatePendingPurchase $pendingPurchases,
        private readonly CatalogPurchaseSnapshot $snapshots,
        private readonly ResolvePaymentProviderOfferMapping $mappings,
        private readonly CreateGatewayPaymentAttempt $payments,
        private readonly ServicePriceResolver $prices,
    ) {}

    public function onlineProduct(
        Organization $organization,
        Client $client,
        Service $product,
        string $gateway,
        string $idempotencyKey,
        string $buyerEmail,
        ?User $actor = null,
        ?string $successfulReturnUrl = null,
        ?string $failureReturnUrl = null,
        ?string $cancelReturnUrl = null,
    ): CommercePurchaseCheckoutResult {
        return $this->catalogProduct(
            organization: $organization,
            client: $client,
            product: $product,
            expectedType: CatalogItemType::OnlineProduct,
            purchaseKind: 'online_product',
            gateway: $gateway,
            idempotencyKey: $idempotencyKey,
            buyerEmail: $buyerEmail,
            actor: $actor,
            successfulReturnUrl: $successfulReturnUrl,
            failureReturnUrl: $failureReturnUrl,
            cancelReturnUrl: $cancelReturnUrl,
        );
    }

    public function physicalProduct(
        Organization $organization,
        Client $client,
        Service $product,
        string $gateway,
        string $idempotencyKey,
        string $buyerEmail,
        ?User $actor = null,
        ?string $successfulReturnUrl = null,
        ?string $failureReturnUrl = null,
        ?string $cancelReturnUrl = null,
    ): CommercePurchaseCheckoutResult {
        return $this->catalogProduct(
            organization: $organization,
            client: $client,
            product: $product,
            expectedType: CatalogItemType::PhysicalProduct,
            purchaseKind: 'physical_product',
            gateway: $gateway,
            idempotencyKey: $idempotencyKey,
            buyerEmail: $buyerEmail,
            actor: $actor,
            successfulReturnUrl: $successfulReturnUrl,
            failureReturnUrl: $failureReturnUrl,
            cancelReturnUrl: $cancelReturnUrl,
        );
    }

    public function giftCertificate(
        Organization $organization,
        Client $client,
        Service $product,
        string $gateway,
        string $idempotencyKey,
        string $buyerEmail,
        ?User $actor = null,
        ?string $successfulReturnUrl = null,
        ?string $failureReturnUrl = null,
        ?string $cancelReturnUrl = null,
    ): CommercePurchaseCheckoutResult {
        return $this->catalogProduct(
            organization: $organization,
            client: $client,
            product: $product,
            expectedType: CatalogItemType::GiftCertificate,
            purchaseKind: 'gift_certificate',
            gateway: $gateway,
            idempotencyKey: $idempotencyKey,
            buyerEmail: $buyerEmail,
            actor: $actor,
            successfulReturnUrl: $successfulReturnUrl,
            failureReturnUrl: $failureReturnUrl,
            cancelReturnUrl: $cancelReturnUrl,
        );
    }

    private function catalogProduct(
        Organization $organization,
        Client $client,
        Service $product,
        CatalogItemType $expectedType,
        string $purchaseKind,
        string $gateway,
        string $idempotencyKey,
        string $buyerEmail,
        ?User $actor,
        ?string $successfulReturnUrl,
        ?string $failureReturnUrl,
        ?string $cancelReturnUrl,
    ): CommercePurchaseCheckoutResult {
        if ((int) $product->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['product' => 'Выбранный товар не относится к текущей организации.']);
        }

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['client' => 'Клиент не относится к текущей организации.']);
        }

        if ((int) $product->organization_id !== (int) $organization->getKey()
            || ! $product->is_active
            || $product->catalogItemType() !== $expectedType) {
            throw ValidationException::withMessages(['product' => 'Выбранный товар недоступен для покупки.']);
        }

        $sellableType = Service::class;
        $priceAndMapping = $this->priceAndMapping($organization, $product, $gateway, $sellableType);
        $amount = $priceAndMapping['amount'];
        $mapping = $priceAndMapping['mapping'];
        $productSnapshot = $this->snapshots->forService($product, $amount, $purchaseKind);

        return $this->start(
            organization: $organization,
            client: $client,
            gateway: $gateway,
            idempotencyKey: $idempotencyKey,
            buyerEmail: $buyerEmail,
            sellableType: $sellableType,
            sellableId: (int) $product->getKey(),
            amount: $amount,
            itemSnapshot: $productSnapshot,
            purchaseSnapshot: [
                'kind' => $purchaseKind,
                'product' => $productSnapshot,
            ],
            fulfillmentProvider: $purchaseKind === 'gift_certificate' ? 'gift_certificate' : 'manual',
            mapping: $mapping,
            actor: $actor,
            successfulReturnUrl: $successfulReturnUrl,
            failureReturnUrl: $failureReturnUrl,
            cancelReturnUrl: $cancelReturnUrl,
        );
    }

    public function trackerPlan(
        Organization $organization,
        Client $client,
        TrackerPlanVersion $version,
        string $gateway,
        string $idempotencyKey,
        string $buyerEmail,
        ?User $actor = null,
        ?string $successfulReturnUrl = null,
        ?string $failureReturnUrl = null,
        ?string $cancelReturnUrl = null,
    ): CommercePurchaseCheckoutResult {
        if ((int) $version->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['plan' => 'Выбранный тариф не относится к текущей организации.']);
        }

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['client' => 'Клиент не относится к текущей организации.']);
        }

        $version->loadMissing('plan');
        $plan = $version->getRelationValue('plan');
        if (! $plan instanceof TrackerPlan
            || (int) $version->organization_id !== (int) $organization->getKey()
            || ! $plan->is_active
            || ! $plan->is_visible
            || ! $version->included_access
            || $version->price_minor <= 0) {
            throw ValidationException::withMessages(['plan' => 'Выбранный тариф недоступен для покупки.']);
        }

        $currency = $version->currencyCode();
        $planSnapshot = [
            'kind' => 'tracker_plan',
            'plan_id' => (int) $version->tracker_plan_id,
            'plan_version_id' => (int) $version->getKey(),
            'plan_name' => (string) $plan->name,
            'version' => (int) $version->version,
            'price_minor' => (int) $version->price_minor,
            'currency' => $currency->value,
            'duration_days' => (int) $version->duration_days,
            'description' => $version->description,
            'monthly_practice' => $version->monthly_practice,
            'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];

        return $this->start(
            organization: $organization,
            client: $client,
            gateway: $gateway,
            idempotencyKey: $idempotencyKey,
            buyerEmail: $buyerEmail,
            sellableType: TrackerPlanVersion::class,
            sellableId: (int) $version->getKey(),
            amount: Money::ofMinor($version->price_minor, $currency),
            itemSnapshot: $planSnapshot,
            purchaseSnapshot: $planSnapshot,
            fulfillmentProvider: 'tracker_entitlement',
            mapping: null,
            actor: $actor,
            successfulReturnUrl: $successfulReturnUrl,
            failureReturnUrl: $failureReturnUrl,
            cancelReturnUrl: $cancelReturnUrl,
        );
    }

    /**
     * @param  array<string, mixed>  $itemSnapshot
     * @param  array<string, mixed>  $purchaseSnapshot
     */
    private function start(
        Organization $organization,
        Client $client,
        string $gateway,
        string $idempotencyKey,
        string $buyerEmail,
        string $sellableType,
        int $sellableId,
        Money $amount,
        array $itemSnapshot,
        array $purchaseSnapshot,
        string $fulfillmentProvider,
        ?PaymentProviderOfferMapping $mapping,
        ?User $actor,
        ?string $successfulReturnUrl,
        ?string $failureReturnUrl,
        ?string $cancelReturnUrl,
    ): CommercePurchaseCheckoutResult {
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['client' => 'Клиент не относится к текущей организации.']);
        }

        $mapping ??= $this->mappings->handle(
            (int) $organization->getKey(),
            $gateway,
            $sellableType,
            $sellableId,
            $amount->currency(),
        );

        $requestData = [
            'gateway' => $gateway,
            'sellable_type' => $sellableType,
            'sellable_id' => $sellableId,
            'amount_minor' => $amount->minorUnitsString(),
            'currency' => $amount->currency()->value,
            'fulfillment_provider' => $fulfillmentProvider,
            'provider_offer_id' => $mapping->external_offer_id,
            'buyer_email' => mb_strtolower(trim($buyerEmail)),
            'successful_return_url' => $successfulReturnUrl,
            'failure_return_url' => $failureReturnUrl,
            'cancel_return_url' => $cancelReturnUrl,
            'purchase_snapshot' => $this->requestSnapshot($purchaseSnapshot),
        ];
        $purchase = $this->pendingPurchases->handle(
            organization: $organization,
            client: $client,
            idempotencyKey: $idempotencyKey,
            amount: $amount,
            sellableType: $sellableType,
            sellableId: $sellableId,
            itemSnapshot: $itemSnapshot,
            purchaseSnapshot: $purchaseSnapshot,
            fulfillmentProvider: $fulfillmentProvider,
            requestData: $requestData,
            actor: $actor,
        );

        $obligation = $purchase->obligation()->firstOrFail();
        $transaction = $this->payments->handle(
            organization: $organization,
            obligation: $obligation,
            gatewayName: $gateway,
            idempotencyKey: $idempotencyKey,
            buyerEmail: $buyerEmail,
            providerOfferId: $mapping->external_offer_id,
            successfulReturnUrl: $successfulReturnUrl,
            failureReturnUrl: $failureReturnUrl,
            cancelReturnUrl: $cancelReturnUrl,
            actor: $actor,
            source: 'commerce_checkout',
        );

        return new CommercePurchaseCheckoutResult($purchase->refresh(), $transaction);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function requestSnapshot(array $snapshot): array
    {
        unset($snapshot['captured_at']);
        if (is_array($snapshot['product'] ?? null)) {
            unset($snapshot['product']['captured_at']);
        }

        return $snapshot;
    }

    /** @return array{amount: Money, mapping: PaymentProviderOfferMapping} */
    private function priceAndMapping(
        Organization $organization,
        Service $product,
        string $gateway,
        string $sellableType,
    ): array {
        foreach ($this->prices->candidateCurrencies($product, $organization) as $currency) {
            $amount = $this->prices->resolve($product, $currency, $organization);
            if (! $amount instanceof Money) {
                continue;
            }

            try {
                $mapping = $this->mappings->handle(
                    (int) $organization->getKey(),
                    $gateway,
                    $sellableType,
                    (int) $product->getKey(),
                    $amount->currency(),
                );

                return ['amount' => $amount, 'mapping' => $mapping];
            } catch (ValidationException) {
            }
        }

        throw ValidationException::withMessages(['product' => 'У товара не настроена цена и предложение Lava для доступной валюты.']);
    }
}
