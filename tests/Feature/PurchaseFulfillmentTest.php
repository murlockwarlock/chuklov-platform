<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Application\CompletePaidPurchase;
use App\Modules\Commerce\Application\FulfillManualPurchaseItem;
use App\Modules\Commerce\Application\StartPurchaseCheckout;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Models\FulfillmentEvent;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\DrainPaymentGatewayEvents;
use App\Modules\Finance\Application\ReceiveLavaWebhook;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Tracker\Domain\Models\TrackerEntitlement;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_fulfillment_requires_paid_purchase_and_is_idempotent(): void
    {
        [$organization, $admin, $client, $product] = $this->fixture();
        $this->mapping($organization, Service::class, $product->getKey(), '836b9fc5-7ae9-4a27-9642-592bc44072b7');
        $checkout = $this->checkout($organization, $client, $product, 'fulfillment-manual-1');
        $fulfillment = $checkout->purchase->items()->sole()->fulfillment;

        $this->expectException(ValidationException::class);
        app(FulfillManualPurchaseItem::class)->handle($admin, $fulfillment);
    }

    public function test_manual_fulfillment_completes_only_after_settlement_and_replay_is_idempotent(): void
    {
        [$organization, $admin, $client, $product] = $this->fixture();
        $this->mapping($organization, Service::class, $product->getKey(), '836b9fc5-7ae9-4a27-9642-592bc44072b7');
        $checkout = $this->checkout($organization, $client, $product, 'fulfillment-manual-2');
        $fulfillment = $checkout->purchase->items()->sole()->fulfillment;
        $this->settle($organization, $checkout->transaction->provider_reference, 8000, 'manual');

        self::assertSame('paid', $checkout->purchase->fresh()->status->value);
        self::assertSame(CommerceFulfillmentStatus::Pending, $fulfillment->fresh()->status);

        $fulfilled = app(FulfillManualPurchaseItem::class)->handle($admin, $fulfillment);
        $again = app(FulfillManualPurchaseItem::class)->handle($admin, $fulfilled);

        self::assertSame(CommerceFulfillmentStatus::Fulfilled, $again->status);
        self::assertSame(1, FulfillmentEvent::query()->where('fulfillment_id', $fulfillment->getKey())->count());
    }

    public function test_tracker_fulfillment_uses_existing_entitlement_policy_and_duplicate_webhook_does_not_extend_twice(): void
    {
        [$organization, , $client] = $this->baseFixture();
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
        $this->mapping($organization, TrackerPlanVersion::class, $version->getKey(), '836b9fc5-7ae9-4a27-9642-592bc44072b7');
        $checkout = $this->trackerCheckout($organization, $client, $version);
        $payload = $this->paymentPayload($checkout->transaction->provider_reference, 30);
        app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $payload);
        app(DrainPaymentGatewayEvents::class)->handle($organization->getKey(), 'lava', $checkout->transaction->provider_reference);

        $entitlement = TrackerEntitlement::query()->where('organization_id', $organization->getKey())->where('client_id', $client->getKey())->sole();
        $endsAt = $entitlement->ends_at;
        self::assertSame(30, $entitlement->applied_duration_days);
        self::assertSame(CommerceFulfillmentStatus::Fulfilled, $checkout->purchase->items()->sole()->fulfillment->status);
        $events = FulfillmentEvent::query()
            ->where('fulfillment_id', $checkout->purchase->items()->sole()->fulfillment->getKey())
            ->orderBy('id')
            ->get();
        self::assertSame(['pending', 'processing'], $events->pluck('from_status')->all());
        self::assertSame(['processing', 'fulfilled'], $events->pluck('to_status')->all());

        app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $payload);
        app(DrainPaymentGatewayEvents::class)->handle($organization->getKey(), 'lava', $checkout->transaction->provider_reference);

        self::assertSame(1, TrackerEntitlement::query()->where('organization_id', $organization->getKey())->where('client_id', $client->getKey())->count());
        self::assertTrue($endsAt->equalTo(TrackerEntitlement::query()->whereKey($entitlement->getKey())->value('ends_at')));
    }

    public function test_tracker_fulfillment_failure_retries_are_bounded_without_duplicate_failure_events(): void
    {
        [$organization, , $client] = $this->baseFixture();
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
        $this->mapping($organization, TrackerPlanVersion::class, $version->getKey(), '836b9fc5-7ae9-4a27-9642-592bc44072b7');
        $checkout = $this->trackerCheckout($organization, $client, $version);
        $item = $checkout->purchase->items()->sole();
        $item->forceFill([
            'product_snapshot' => [
                ...$item->product_snapshot,
                'plan_version_id' => 999999,
            ],
        ])->save();
        $this->settle($organization, $checkout->transaction->provider_reference, 30, 'bounded-failure');

        $maxAttempts = max(1, (int) config('payments.fulfillment.max_attempts', 5));
        for ($attempt = 0; $attempt < $maxAttempts + 2; $attempt++) {
            app(CompletePaidPurchase::class)->handle($organization->getKey(), $checkout->transaction->getKey());
        }

        self::assertSame($maxAttempts, $item->fulfillment->refresh()->attempts);
        self::assertSame(CommerceFulfillmentStatus::Failed, $item->fulfillment->status);
        self::assertSame(
            1,
            ScenarioEvent::query()
                ->where('organization_id', $organization->getKey())
                ->where('event_name', ScenarioEventType::FulfillmentFailed->value)
                ->count(),
        );
        self::assertSame(0, TrackerEntitlement::query()->where('organization_id', $organization->getKey())->count());
    }

    private function checkout(Organization $organization, Client $client, Service $product, string $key): mixed
    {
        Http::fake([
            '*' => Http::response([
                'id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/course',
            ], 201),
        ]);

        return app(StartPurchaseCheckout::class)->onlineProduct(
            organization: $organization,
            client: $client,
            product: $product,
            gateway: 'lava',
            idempotencyKey: $key,
            buyerEmail: (string) $client->email,
        );
    }

    private function trackerCheckout(Organization $organization, Client $client, TrackerPlanVersion $version): mixed
    {
        Http::fake([
            '*' => Http::response([
                'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/tracker',
            ], 201),
        ]);

        return app(StartPurchaseCheckout::class)->trackerPlan(
            organization: $organization,
            client: $client,
            version: $version,
            gateway: 'lava',
            idempotencyKey: 'fulfillment-tracker-1',
            buyerEmail: (string) $client->email,
        );
    }

    private function settle(Organization $organization, string $providerReference, int $amount, string $suffix): void
    {
        $payload = $this->paymentPayload($providerReference, $amount);
        $payload['buyer']['email'] = $suffix.'@example.com';
        app(ReceiveLavaWebhook::class)->handle($organization->getKey(), $payload);
        app(DrainPaymentGatewayEvents::class)->handle($organization->getKey(), 'lava', $providerReference);
    }

    private function paymentPayload(string $providerReference, int|float $amount): array
    {
        return [
            'eventType' => 'payment.success',
            'contractId' => $providerReference,
            'buyer' => ['email' => 'client@example.com'],
            'amount' => $amount,
            'currency' => 'USD',
            'timestamp' => '2026-09-17T10:00:00Z',
            'status' => 'completed',
        ];
    }

    private function fixture(): array
    {
        [$organization, $admin, $client] = $this->baseFixture();
        $product = Service::factory()->forOrganization($organization)->create([
            'name' => 'Course',
            'catalog_type' => CatalogItemType::OnlineProduct->value,
            'price_minor' => 800000,
            'price_currency' => 'USD',
        ]);

        return [$organization, $admin, $client, $product];
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

        return [$organization, $admin, $client];
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
    }
}
