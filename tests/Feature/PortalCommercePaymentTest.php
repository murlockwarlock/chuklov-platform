<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\CreateFinancialObligation;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Enums\ServicePaymentRequirement;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class PortalCommercePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_product_is_published_in_catalog_and_starts_lava_checkout_without_booking(): void
    {
        [$organization, $admin, $client] = $this->organizationFixture();
        $product = Service::factory()->forOrganization($organization)->create([
            'name' => 'Целительство',
            'catalog_type' => CatalogItemType::OnlineProduct->value,
            'price_minor' => 150000,
            'price_currency' => 'RUB',
            'duration_minutes' => null,
        ]);
        $this->mapping($organization, $product, 'RUB');
        $this->credential($organization);
        $this->currency($organization, $admin, 'RUB');
        Http::fake([
            '*' => Http::response([
                'id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/course',
            ], 201),
        ]);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.services.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('services.0.catalogType', CatalogItemType::OnlineProduct->value)
                ->where('services.0.purchaseUrl', route('portal.services.purchase', $product->getKey())));

        $response = $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.services.purchase', $product->getKey()), [
                'idempotency_key' => 'portal-course-purchase-1',
            ]);

        $response->assertRedirect('https://pay.lava.top/course');
        self::assertDatabaseCount('bookings', 0);
        self::assertDatabaseHas('commerce_purchases', [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => 'pending_payment',
            'total_amount_minor' => 150000,
        ]);
        self::assertDatabaseHas('payment_gateway_transactions', [
            'organization_id' => $organization->getKey(),
            'gateway' => 'lava',
            'status' => PaymentGatewayStatus::Pending->value,
            'amount_minor' => 150000,
            'currency' => 'RUB',
        ]);
    }

    public function test_prepay_full_booking_can_start_server_defined_full_lava_payment(): void
    {
        [$organization, $admin, $client] = $this->organizationFixture();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'name' => 'Сеанс',
            'catalog_type' => CatalogItemType::Service->value,
            'price_minor' => 800000,
            'price_currency' => 'RUB',
            'payment_requirement' => ServicePaymentRequirement::PrepayFull->value,
        ]);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create(['status' => 'confirmed']);
        $this->currency($organization, $admin, 'RUB');
        app(OrganizationContext::class)->set($organization);
        $obligation = app(CreateFinancialObligation::class)->handle($admin, $booking);
        self::assertInstanceOf(FinancialObligation::class, $obligation);
        $this->mapping($organization, $service, 'RUB');
        $this->credential($organization);
        Http::fake([
            '*' => Http::response([
                'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/session',
            ], 201),
        ]);

        $response = $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.finance.lava.start', $obligation->getKey()), [
                'idempotency_key' => 'portal-session-payment-1',
                'amount' => '1.00',
            ]);

        $response->assertRedirect('https://pay.lava.top/session');
        $transaction = $obligation->gatewayTransactions()->sole();
        self::assertSame(800000, $transaction->amount_minor);
        self::assertSame(PaymentGatewayStatus::Pending, $transaction->status);
        self::assertSame('outstanding', app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey())
            ->status
            ->value);
    }

    public function test_client_cannot_start_lava_payment_for_another_clients_obligation(): void
    {
        [$organization, $admin, $client] = $this->organizationFixture();
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 800000,
            'price_currency' => 'RUB',
            'payment_requirement' => ServicePaymentRequirement::PrepayFull->value,
        ]);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create(['status' => 'confirmed']);
        $this->currency($organization, $admin, 'RUB');
        app(OrganizationContext::class)->set($organization);
        $obligation = app(CreateFinancialObligation::class)->handle($admin, $booking);

        $this->withSession(['client_portal.client_id' => $otherClient->getKey()])
            ->post(route('portal.finance.lava.start', $obligation?->getKey()), [
                'idempotency_key' => 'foreign-obligation-payment',
            ])
            ->assertNotFound();
    }

    /** @return array{Organization, User, Client} */
    private function organizationFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create([
            'email' => 'client@example.com',
        ]);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());

        return [$organization, $admin, $client];
    }

    private function currency(Organization $organization, User $admin, string $currency): void
    {
        app(OrganizationContext::class)->set($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => $currency,
            'display_currency' => $currency,
            'allowed_currencies' => [$currency],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
    }

    private function credential(Organization $organization): void
    {
        $credential = OrganizationCredential::factory()->forOrganization($organization)->make([
            'provider' => 'lava',
            'credential_name' => 'default',
            'status' => CredentialStatus::Active->value,
        ]);
        $credential->forceFill([
            'credentials' => ['api_key' => 'lava-api-key'],
        ])->save();
    }

    private function mapping(Organization $organization, Service $service, string $currency): void
    {
        $mapping = new PaymentProviderOfferMapping;
        $mapping->forceFill([
            'organization_id' => $organization->getKey(),
            'gateway' => 'lava',
            'sellable_type' => Service::class,
            'sellable_id' => $service->getKey(),
            'currency' => $currency,
            'external_offer_id' => '836b9fc5-7ae9-4a27-9642-592bc44072b7',
            'is_active' => true,
        ])->save();
    }
}
