<?php

namespace App\Modules\Commerce\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Finance\Application\CreateGatewayPaymentAttempt;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\ResolvePaymentProviderOfferMapping;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Application\ServicePriceResolver;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StartPurchaseCheckout
{
    public function __construct(
        private readonly CurrencyConfigurationService $configuration,
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
        $productSnapshot = $this->catalogProductSnapshot($product, $amount, $purchaseKind);

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
            fulfillmentProvider: 'manual',
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
        if ((int) $version->organization_id !== (int) $organization->getKey()
            || ! $version->plan?->is_active
            || ! $version->plan?->is_visible
            || ! $version->included_access
            || $version->price_minor <= 0) {
            throw ValidationException::withMessages(['plan' => 'Выбранный тариф недоступен для покупки.']);
        }

        $currency = $version->currencyCode();
        $planSnapshot = [
            'kind' => 'tracker_plan',
            'plan_id' => (int) $version->tracker_plan_id,
            'plan_version_id' => (int) $version->getKey(),
            'plan_name' => (string) $version->plan->name,
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

        $requestHash = hash('sha256', json_encode([
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
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            $purchase = DB::transaction(function () use (
                $organization,
                $client,
                $idempotencyKey,
                $requestHash,
                $amount,
                $itemSnapshot,
                $purchaseSnapshot,
                $fulfillmentProvider,
                $actor,
            ): Purchase {
                $existing = Purchase::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('checkout_idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof Purchase) {
                    if ($existing->checkout_request_hash !== $requestHash
                        || (int) $existing->client_id !== (int) $client->getKey()) {
                        throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой покупки.']);
                    }

                    return $existing;
                }

                $configuration = $this->configuration->configuration($organization);
                $baseSnapshot = $this->configuration->convert($organization, $amount, $configuration->base_currency);
                $displaySnapshot = $this->configuration->convert($organization, $amount, $configuration->display_currency);
                $purchase = new Purchase;
                $purchase->forceFill([
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'status' => PurchaseStatus::PendingPayment->value,
                    'total_amount_minor' => $amount->minorUnits(),
                    'currency' => $amount->currency()->value,
                    'checkout_idempotency_key' => $idempotencyKey,
                    'checkout_request_hash' => $requestHash,
                    'purchase_snapshot' => [
                        ...$purchaseSnapshot,
                        'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                    ],
                ])->save();
                $item = new PurchaseItem;
                $item->forceFill([
                    'organization_id' => $organization->getKey(),
                    'purchase_id' => $purchase->getKey(),
                    'sellable_type' => $this->sellableType($itemSnapshot),
                    'sellable_id' => $this->sellableId($itemSnapshot),
                    'quantity' => 1,
                    'amount_minor' => $amount->minorUnits(),
                    'currency' => $amount->currency()->value,
                    'product_snapshot' => $itemSnapshot,
                    'fulfillment_provider' => $fulfillmentProvider,
                ])->save();
                $fulfillment = new PurchaseFulfillment;
                $fulfillment->forceFill([
                    'organization_id' => $organization->getKey(),
                    'purchase_item_id' => $item->getKey(),
                    'provider_type' => $fulfillmentProvider,
                    'status' => CommerceFulfillmentStatus::Pending->value,
                    'attempts' => 0,
                ])->save();
                $obligation = new FinancialObligation;
                $obligation->forceFill([
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'booking_id' => null,
                    'service_id' => null,
                    'purchase_id' => $purchase->getKey(),
                    'amount_minor' => $amount->minorUnits(),
                    'currency' => $amount->currency()->value,
                    'base_amount_minor' => (int) $baseSnapshot->targetAmountMinor,
                    'base_currency' => $baseSnapshot->targetCurrency->value,
                    'display_amount_minor' => (int) $displaySnapshot->targetAmountMinor,
                    'display_currency' => $displaySnapshot->targetCurrency->value,
                    'payment_amount_minor' => $amount->minorUnits(),
                    'payment_currency' => $amount->currency()->value,
                    'settlement_amount_minor' => $amount->minorUnits(),
                    'settlement_currency' => $amount->currency()->value,
                    'price_snapshot' => [
                        'purchase_id' => (int) $purchase->getKey(),
                        'item_id' => (int) $item->getKey(),
                        'snapshot' => $itemSnapshot,
                    ],
                    'conversion_snapshots' => [
                        'base' => $baseSnapshot->toArray(),
                        'display' => $displaySnapshot->toArray(),
                    ],
                    'creation_key' => 'purchase.payment:'.$organization->getKey().':'.$purchase->getKey(),
                    'created_by_user_id' => $actor?->getKey(),
                ])->save();

                return $purchase->refresh();
            });
        } catch (QueryException $exception) {
            if (! $this->isCheckoutIdempotencyConflict($exception)) {
                throw $exception;
            }

            $purchase = $this->existingPurchase(
                organization: $organization,
                client: $client,
                idempotencyKey: $idempotencyKey,
                sellableType: $sellableType,
                sellableId: $sellableId,
            );
            if (! $purchase instanceof Purchase) {
                throw $exception;
            }

            if ($purchase->checkout_request_hash !== $requestHash) {
                throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой покупки.']);
            }
        }

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

    private function existingPurchase(
        Organization $organization,
        Client $client,
        string $idempotencyKey,
        string $sellableType,
        int $sellableId,
    ): ?Purchase {
        $purchase = Purchase::query()
            ->where('organization_id', $organization->getKey())
            ->where('checkout_idempotency_key', $idempotencyKey)
            ->with('items')
            ->first();
        if (! $purchase instanceof Purchase) {
            return null;
        }

        if ((int) $purchase->client_id !== (int) $client->getKey()) {
            throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другого клиента.']);
        }

        $item = $purchase->items->sole();
        if ($item->sellable_type !== $sellableType || (int) $item->sellable_id !== $sellableId) {
            throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой покупки.']);
        }

        return $purchase;
    }

    private function isCheckoutIdempotencyConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['19', '23000', '23505'], true)
            && (str_contains($exception->getMessage(), 'commerce_purchases_checkout_idempotency_unique')
                || str_contains($exception->getMessage(), 'checkout_idempotency_key'));
    }

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

    private function catalogProductSnapshot(Service $product, Money $amount, string $kind): array
    {
        return [
            'kind' => $kind,
            'service_id' => (int) $product->getKey(),
            'name' => (string) $product->name,
            'summary' => $product->summary,
            'description_ru' => $product->description_ru,
            'description_en' => $product->description_en,
            'catalog_type' => $product->catalogItemType()->value,
            'price_minor' => $amount->minorUnits(),
            'currency' => $amount->currency()->value,
            'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    private function sellableType(array $snapshot): string
    {
        return ($snapshot['kind'] ?? null) === 'tracker_plan'
            ? TrackerPlanVersion::class
            : Service::class;
    }

    private function sellableId(array $snapshot): int
    {
        return (int) (($snapshot['kind'] ?? null) === 'tracker_plan'
            ? ($snapshot['plan_version_id'] ?? 0)
            : ($snapshot['service_id'] ?? 0));
    }
}
