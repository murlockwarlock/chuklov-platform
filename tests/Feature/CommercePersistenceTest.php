<?php

namespace Tests\Feature;

use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CommercePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fulfillment_is_scoped_to_an_individual_purchase_item(): void
    {
        [$organization, $client, $service] = $this->onlineProduct();
        $purchase = $this->purchase($organization, $client);
        $item = $this->purchaseItem($organization, $purchase, $service);
        $fulfillment = new PurchaseFulfillment;
        $fulfillment->forceFill([
            'organization_id' => $organization->getKey(),
            'purchase_item_id' => $item->getKey(),
            'provider_type' => 'manual',
            'status' => CommerceFulfillmentStatus::Pending->value,
            'attempts' => 0,
        ])->save();

        self::assertSame($item->getKey(), $fulfillment->item->getKey());
        self::assertSame($purchase->getKey(), $fulfillment->item->purchase->getKey());
        self::assertSame(PurchaseStatus::PendingPayment, $purchase->status);
    }

    public function test_active_provider_mapping_is_unique_per_sellable_and_currency(): void
    {
        [$organization, , $service] = $this->onlineProduct();
        $attributes = [
            'organization_id' => $organization->getKey(),
            'gateway' => 'lava',
            'sellable_type' => Service::class,
            'sellable_id' => $service->getKey(),
            'currency' => 'RUB',
            'external_offer_id' => 'offer-rub',
            'is_active' => true,
        ];
        $mapping = new PaymentProviderOfferMapping;
        $mapping->forceFill($attributes)->save();

        $this->expectException(QueryException::class);
        $duplicate = new PaymentProviderOfferMapping;
        $duplicate->forceFill($attributes)->save();
    }

    public function test_provider_mapping_allows_one_active_mapping_per_currency(): void
    {
        [$organization, , $service] = $this->onlineProduct();
        foreach (['RUB' => 'offer-rub', 'USD' => 'offer-usd'] as $currency => $offerId) {
            $mapping = new PaymentProviderOfferMapping;
            $mapping->forceFill([
                'organization_id' => $organization->getKey(),
                'gateway' => 'lava',
                'sellable_type' => Service::class,
                'sellable_id' => $service->getKey(),
                'currency' => $currency,
                'external_offer_id' => $offerId,
                'is_active' => true,
            ])->save();
        }

        self::assertSame(2, PaymentProviderOfferMapping::query()->count());
    }

    public function test_cross_organization_purchase_item_reference_is_rejected(): void
    {
        [$firstOrganization, $client, $service] = $this->onlineProduct();
        $purchase = $this->purchase($firstOrganization, $client);
        $secondOrganization = Organization::factory()->create();

        $this->expectException(QueryException::class);
        $item = new PurchaseItem;
        $item->forceFill([
            'organization_id' => $secondOrganization->getKey(),
            'purchase_id' => $purchase->getKey(),
            'sellable_type' => Service::class,
            'sellable_id' => $service->getKey(),
            'quantity' => 1,
            'amount_minor' => 800000,
            'currency' => 'RUB',
            'product_snapshot' => ['name' => 'Course'],
            'fulfillment_provider' => 'manual',
        ])->save();
    }

    public function test_purchase_backed_obligation_does_not_require_a_fake_booking(): void
    {
        [$organization, $client, $service] = $this->onlineProduct();
        $purchase = $this->purchase($organization, $client);
        $item = $this->purchaseItem($organization, $purchase, $service);
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => null,
            'service_id' => null,
            'purchase_id' => $purchase->getKey(),
            'amount_minor' => 800000,
            'currency' => 'RUB',
            'base_amount_minor' => 800000,
            'base_currency' => 'RUB',
            'display_amount_minor' => 800000,
            'display_currency' => 'RUB',
            'payment_amount_minor' => 800000,
            'payment_currency' => 'RUB',
            'settlement_amount_minor' => 800000,
            'settlement_currency' => 'RUB',
            'price_snapshot' => [
                'purchase_id' => $purchase->getKey(),
                'item_id' => $item->getKey(),
                'name' => 'Course',
            ],
            'conversion_snapshots' => [],
            'creation_key' => 'purchase.payment:'.$organization->getKey().':'.$purchase->getKey(),
        ])->save();

        self::assertNull($obligation->booking_id);
        self::assertNull($obligation->service_id);
        self::assertSame($purchase->getKey(), $obligation->purchase_id);
        self::assertSame($purchase->getKey(), $obligation->purchase->getKey());
    }

    public function test_postgres_rejects_an_obligation_with_multiple_subjects(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL subject checks are verified on the PostgreSQL gate.');
        }

        [$organization, $client, $service] = $this->onlineProduct();
        $purchase = $this->purchase($organization, $client);
        $this->purchaseItem($organization, $purchase, $service);

        $this->expectException(QueryException::class);
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => 1,
            'service_id' => $service->getKey(),
            'purchase_id' => $purchase->getKey(),
            'amount_minor' => 800000,
            'currency' => 'RUB',
            'base_amount_minor' => 800000,
            'base_currency' => 'RUB',
            'display_amount_minor' => 800000,
            'display_currency' => 'RUB',
            'payment_amount_minor' => 800000,
            'payment_currency' => 'RUB',
            'settlement_amount_minor' => 800000,
            'settlement_currency' => 'RUB',
            'price_snapshot' => [],
            'conversion_snapshots' => [],
            'creation_key' => 'invalid-subject',
        ])->save();
    }

    private function onlineProduct(): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'catalog_type' => CatalogItemType::OnlineProduct->value,
            'price_minor' => 800000,
            'price_currency' => 'RUB',
        ]);

        return [$organization, $client, $service];
    }

    private function purchase(Organization $organization, Client $client): Purchase
    {
        $purchase = new Purchase;
        $purchase->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => PurchaseStatus::PendingPayment->value,
            'total_amount_minor' => 800000,
            'currency' => 'RUB',
            'purchase_snapshot' => ['kind' => 'online_product'],
        ])->save();

        return $purchase->refresh();
    }

    private function purchaseItem(Organization $organization, Purchase $purchase, Service $service): PurchaseItem
    {
        $item = new PurchaseItem;
        $item->forceFill([
            'organization_id' => $organization->getKey(),
            'purchase_id' => $purchase->getKey(),
            'sellable_type' => Service::class,
            'sellable_id' => $service->getKey(),
            'quantity' => 1,
            'amount_minor' => 800000,
            'currency' => 'RUB',
            'product_snapshot' => [
                'name' => $service->name,
                'catalog_type' => $service->catalogItemType()->value,
            ],
            'fulfillment_provider' => 'manual',
        ])->save();

        return $item->refresh();
    }
}
