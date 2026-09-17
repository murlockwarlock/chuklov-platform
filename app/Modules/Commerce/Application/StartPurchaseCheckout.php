<?php

namespace App\Modules\Commerce\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Finance\Application\CreateGatewayPaymentAttempt;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\ResolvePaymentProviderOfferMapping;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StartPurchaseCheckout
{
    public function __construct(
        private readonly CurrencyConfigurationService $configuration,
        private readonly ResolvePaymentProviderOfferMapping $mappings,
        private readonly CreateGatewayPaymentAttempt $payments,
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
        if ((int) $product->organization_id !== (int) $organization->getKey()
            || ! $product->is_active
            || $product->catalogItemType() !== CatalogItemType::OnlineProduct) {
            throw ValidationException::withMessages(['product' => 'Выбранный товар не является онлайн-продуктом.']);
        }

        if ($product->price_minor === null || $product->price_minor <= 0 || $product->price_currency === null) {
            throw ValidationException::withMessages(['product' => 'У онлайн-продукта не настроена цена.']);
        }

        $currency = CurrencyCode::from((string) $product->price_currency);
        $productSnapshot = $this->onlineProductSnapshot($product, $currency);
        $sellableType = Service::class;

        return $this->start(
            organization: $organization,
            client: $client,
            gateway: $gateway,
            idempotencyKey: $idempotencyKey,
            buyerEmail: $buyerEmail,
            sellableType: $sellableType,
            sellableId: (int) $product->getKey(),
            amount: Money::ofMinor($product->price_minor, $currency),
            itemSnapshot: $productSnapshot,
            purchaseSnapshot: [
                'kind' => 'online_product',
                'product' => $productSnapshot,
            ],
            fulfillmentProvider: 'manual',
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
        ?User $actor,
        ?string $successfulReturnUrl,
        ?string $failureReturnUrl,
        ?string $cancelReturnUrl,
    ): CommercePurchaseCheckoutResult {
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['client' => 'Клиент не относится к текущей организации.']);
        }

        $mapping = $this->mappings->handle(
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
            'purchase_snapshot' => $purchaseSnapshot,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

    private function onlineProductSnapshot(Service $product, CurrencyCode $currency): array
    {
        return [
            'kind' => 'online_product',
            'service_id' => (int) $product->getKey(),
            'name' => (string) $product->name,
            'summary' => $product->summary,
            'description_ru' => $product->description_ru,
            'description_en' => $product->description_en,
            'catalog_type' => CatalogItemType::OnlineProduct->value,
            'price_minor' => (int) $product->price_minor,
            'currency' => $currency->value,
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
