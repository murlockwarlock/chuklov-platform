<?php

namespace App\Modules\Commerce\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreatePendingPurchase
{
    public function __construct(private readonly CurrencyConfigurationService $configuration) {}

    /**
     * @param  array<string, mixed>  $itemSnapshot
     * @param  array<string, mixed>  $purchaseSnapshot
     * @param  array<string, mixed>  $requestData
     */
    public function handle(
        Organization $organization,
        Client $client,
        string $idempotencyKey,
        Money $amount,
        string $sellableType,
        int $sellableId,
        array $itemSnapshot,
        array $purchaseSnapshot,
        string $fulfillmentProvider,
        array $requestData,
        ?User $actor = null,
    ): Purchase {
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['client' => 'Клиент не относится к текущей организации.']);
        }

        if (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 180) {
            throw ValidationException::withMessages(['idempotency_key' => 'Укажите корректный ключ операции.']);
        }

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Сумма покупки должна быть больше нуля.']);
        }

        $requestHash = hash('sha256', json_encode(
            $requestData,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        try {
            return DB::transaction(function () use (
                $organization,
                $client,
                $idempotencyKey,
                $requestHash,
                $amount,
                $sellableType,
                $sellableId,
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
                    $this->assertExistingPurchaseMatches($existing, $client, $requestHash, $sellableType, $sellableId);

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
                    'sellable_type' => $sellableType,
                    'sellable_id' => $sellableId,
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

            return $purchase;
        }
    }

    private function assertExistingPurchaseMatches(
        Purchase $purchase,
        Client $client,
        string $requestHash,
        string $sellableType,
        int $sellableId,
    ): void {
        if ($purchase->checkout_request_hash !== $requestHash
            || (int) $purchase->client_id !== (int) $client->getKey()) {
            throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой покупки.']);
        }

        $item = $purchase->items()
            ->where('organization_id', $purchase->organization_id)
            ->get()
            ->sole();
        if ($item->sellable_type !== $sellableType || (int) $item->sellable_id !== $sellableId) {
            throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой покупки.']);
        }
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
}
