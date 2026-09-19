<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class PortalTrackerPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracker_plan_is_published_with_version_purchase_url_and_starts_lava_checkout(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['email' => 'tracker@example.com']);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        $plan = TrackerPlan::factory()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Tracker 30',
            'is_active' => true,
            'is_visible' => true,
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
        $mapping = new PaymentProviderOfferMapping;
        $mapping->forceFill([
            'organization_id' => $organization->getKey(),
            'gateway' => 'lava',
            'sellable_type' => TrackerPlanVersion::class,
            'sellable_id' => $version->getKey(),
            'currency' => 'USD',
            'external_offer_id' => '836b9fc5-7ae9-4a27-9642-592bc44072b7',
            'is_active' => true,
        ])->save();
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        $credential = OrganizationCredential::factory()->forOrganization($organization)->make([
            'provider' => 'lava',
            'credential_name' => 'default',
            'status' => CredentialStatus::Active->value,
        ]);
        $credential->forceFill(['credentials' => ['api_key' => 'lava-api-key']])->save();
        Http::fake([
            '*' => Http::response([
                'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/tracker',
            ], 201),
        ]);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.tracker'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('tracker.plans.0.versionId', $version->getKey())
                ->where('tracker.plans.0.purchaseUrl', route('portal.tracker.purchase', $version->getKey())));

        $response = $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.tracker.purchase', $version->getKey()), [
                'idempotency_key' => 'portal-tracker-purchase-1',
            ]);

        $response->assertRedirect('https://pay.lava.top/tracker');
        self::assertDatabaseHas('commerce_purchase_items', [
            'sellable_type' => TrackerPlanVersion::class,
            'sellable_id' => $version->getKey(),
            'fulfillment_provider' => 'tracker_entitlement',
        ]);
        self::assertDatabaseHas('payment_gateway_transactions', [
            'gateway' => 'lava',
            'status' => PaymentGatewayStatus::Pending->value,
            'amount_minor' => 3000,
            'currency' => 'USD',
        ]);
    }
}
