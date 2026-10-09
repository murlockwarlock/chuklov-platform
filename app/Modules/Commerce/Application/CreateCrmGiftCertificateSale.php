<?php

namespace App\Modules\Commerce\Application;

use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Validation\ValidationException;

final class CreateCrmGiftCertificateSale
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly ListGiftCertificateOfferings $offerings,
        private readonly CatalogPurchaseSnapshot $snapshots,
        private readonly CreatePendingPurchase $pendingPurchases,
    ) {}

    public function handle(User $actor, int $clientId, int $productId, string $idempotencyKey): FinancialObligation
    {
        $organization = $this->authorization->authorizeManage($actor);
        $client = Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($clientId)
            ->first();
        if (! $client instanceof Client) {
            throw ValidationException::withMessages(['client_id' => 'Выберите клиента из текущей организации.']);
        }

        $product = $this->offerings->find($productId);
        if (! $product instanceof Service) {
            throw ValidationException::withMessages(['product_id' => 'Выберите активный подарочный сертификат из каталога.']);
        }

        $amount = $this->offerings->price($product);
        if ($amount === null) {
            throw ValidationException::withMessages(['product_id' => 'У подарочного сертификата не настроен положительный номинал.']);
        }

        $productSnapshot = $this->snapshots->forService($product, $amount, 'gift_certificate');
        $purchaseSnapshot = [
            'kind' => 'gift_certificate',
            'product' => $productSnapshot,
        ];

        $purchase = $this->pendingPurchases->handle(
            organization: $organization,
            client: $client,
            idempotencyKey: $idempotencyKey,
            amount: $amount,
            sellableType: Service::class,
            sellableId: (int) $product->getKey(),
            itemSnapshot: $productSnapshot,
            purchaseSnapshot: $purchaseSnapshot,
            fulfillmentProvider: 'gift_certificate',
            requestData: [
                'channel' => 'crm_manual',
                'sellable_type' => Service::class,
                'sellable_id' => (int) $product->getKey(),
                'client_id' => (int) $client->getKey(),
                'amount_minor' => $amount->minorUnitsString(),
                'currency' => $amount->currency()->value,
                'fulfillment_provider' => 'gift_certificate',
                'purchase_snapshot' => $this->requestSnapshot($purchaseSnapshot),
            ],
            actor: $actor,
        );

        return $purchase->obligation()->firstOrFail();
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
}
