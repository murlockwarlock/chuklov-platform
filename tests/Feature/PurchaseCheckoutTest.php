<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Application\StartPurchaseCheckout;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_product_creates_snapshot_purchase_obligation_and_lava_checkout_without_booking(): void
    {
        [$organization, $client, $product] = $this->onlineProductFixture();
        $this->mapping($organization, Service::class, $product->getKey(), 'offer-course');
        Http::fake([
            '*' => Http::response([
                'id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/course',
            ], 201),
        ]);

        $result = app(StartPurchaseCheckout::class)->onlineProduct(
            organization: $organization,
            client: $client,
            product: $product,
            gateway: 'lava',
            idempotencyKey: 'course-checkout-1',
            buyerEmail: (string) $client->email,
        );
        $purchase = $result->purchase->refresh();
        $item = $purchase->items()->sole();
        $obligation = $purchase->obligation()->firstOrFail();

        self::assertSame(PaymentGatewayStatus::Pending, $result->transaction->status);
        self::assertSame('https://pay.lava.top/course', $result->transaction->checkout_url);
        self::assertSame('online_product', $purchase->purchase_snapshot['kind']);
        self::assertSame('Course', $item->product_snapshot['name']);
        self::assertNull($obligation->booking_id);
        self::assertNull($obligation->service_id);
        self::assertSame($purchase->getKey(), $obligation->purchase_id);
        self::assertSame(CommerceFulfillmentStatus::Pending, $item->fulfillment->status);
        self::assertSame('manual', $item->fulfillment->provider_type);
        self::assertSame(1, Purchase::query()->count());
        self::assertSame(1, PaymentGatewayTransaction::query()->count());

        $product->forceFill(['name' => 'Edited course', 'price_minor' => 999999])->save();
        self::assertSame('Course', $item->fresh()->product_snapshot['name']);
        self::assertSame(800000, $item->fresh()->amount_minor);
    }

    public function test_duplicate_checkout_is_idempotent_and_does_not_create_a_second_invoice(): void
    {
        [$organization, $client, $product] = $this->onlineProductFixture();
        $this->mapping($organization, Service::class, $product->getKey(), 'offer-course');
        Http::fake([
            '*' => Http::response([
                'id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/course',
            ], 201),
        ]);
        $checkout = app(StartPurchaseCheckout::class);
        $arguments = [
            'organization' => $organization,
            'client' => $client,
            'product' => $product,
            'gateway' => 'lava',
            'idempotencyKey' => 'course-checkout-duplicate',
            'buyerEmail' => (string) $client->email,
        ];

        $first = $checkout->onlineProduct(...$arguments);
        $second = $checkout->onlineProduct(...$arguments);

        self::assertSame($first->purchase->getKey(), $second->purchase->getKey());
        self::assertSame($first->transaction->getKey(), $second->transaction->getKey());
        self::assertSame(1, Purchase::query()->count());
        Http::assertSentCount(1);
    }

    public function test_tracker_plan_checkout_pins_immutable_version_and_uses_tracker_fulfillment(): void
    {
        [$organization, $client] = $this->baseFixture();
        $plan = TrackerPlan::factory()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Tracker 30',
        ]);
        $version = new TrackerPlanVersion;
        $version->forceFill([
            'organization_id' => $organization->getKey(),
            'tracker_plan_id' => $plan->getKey(),
            'version' => 1,
            'price_minor' => 3000,
            'currency' => 'USD',
            'duration_days' => 30,
            'description' => '30 days',
            'included_access' => true,
            'display_order' => 1,
            'created_at' => now(),
        ])->save();
        $plan->forceFill(['current_version_id' => $version->getKey()])->save();
        $this->mapping($organization, TrackerPlanVersion::class, $version->getKey(), 'offer-tracker');
        Http::fake([
            '*' => Http::response([
                'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/tracker',
            ], 201),
        ]);

        $result = app(StartPurchaseCheckout::class)->trackerPlan(
            organization: $organization,
            client: $client,
            version: $version,
            gateway: 'lava',
            idempotencyKey: 'tracker-checkout-1',
            buyerEmail: (string) $client->email,
        );
        $item = $result->purchase->items()->sole();

        self::assertSame(TrackerPlanVersion::class, $item->sellable_type);
        self::assertSame($version->getKey(), $item->sellable_id);
        self::assertSame(30, $item->product_snapshot['duration_days']);
        self::assertSame('tracker_entitlement', $item->fulfillment->provider_type);
        self::assertSame(CommerceFulfillmentStatus::Pending, $item->fulfillment->status);
    }

    public function test_cross_organization_online_product_cannot_be_purchased(): void
    {
        [$organization, $client] = $this->baseFixture();
        $otherOrganization = Organization::factory()->create();
        $product = Service::factory()->forOrganization($otherOrganization)->create([
            'catalog_type' => CatalogItemType::OnlineProduct->value,
            'price_minor' => 800000,
            'price_currency' => 'USD',
        ]);

        $this->expectException(ValidationException::class);
        app(StartPurchaseCheckout::class)->onlineProduct(
            organization: $organization,
            client: $client,
            product: $product,
            gateway: 'lava',
            idempotencyKey: 'cross-org-checkout',
            buyerEmail: (string) $client->email,
        );
    }

    private function onlineProductFixture(): array
    {
        [$organization, $client] = $this->baseFixture();
        $product = Service::factory()->forOrganization($organization)->create([
            'name' => 'Course',
            'catalog_type' => CatalogItemType::OnlineProduct->value,
            'price_minor' => 800000,
            'price_currency' => 'USD',
        ]);

        return [$organization, $client, $product];
    }

    private function baseFixture(): array
    {
        $organization = Organization::factory()->create();
        $credential = OrganizationCredential::factory()->forOrganization($organization)->make([
            'provider' => 'lava',
            'credential_name' => 'default',
            'status' => CredentialStatus::Active->value,
        ]);
        $credential->forceFill([
            'credentials' => ['api_key' => 'lava-api-key', 'webhook_api_key' => 'lava-webhook-key'],
        ])->save();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);

        return [$organization, $client];
    }

    private function mapping(Organization $organization, string $type, int $id, string $offer): void
    {
        $mapping = new PaymentProviderOfferMapping;
        $mapping->forceFill([
            'organization_id' => $organization->getKey(),
            'gateway' => 'lava',
            'sellable_type' => $type,
            'sellable_id' => $id,
            'currency' => 'USD',
            'external_offer_id' => $offer,
            'is_active' => true,
        ])->save();

        self::assertSame($offer, $mapping->external_offer_id);
    }
}
