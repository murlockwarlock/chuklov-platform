<?php

namespace Tests\Feature;

use App\Filament\Resources\FinancialObligations\Pages\ListFinancialObligations;
use App\Models\User;
use App\Modules\Commerce\Application\StartPurchaseCheckout;
use App\Modules\Commerce\Domain\Models\FulfillmentEvent;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class FinanceCommerceCrmTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_crm_exposes_manual_fulfillment_for_paid_digital_product(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Иван Петров']);
        $product = Service::factory()->forOrganization($organization)->create([
            'name' => 'Целительство',
            'catalog_type' => CatalogItemType::OnlineProduct->value,
            'price_minor' => 150000,
            'price_currency' => 'RUB',
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'RUB',
            'allowed_currencies' => ['RUB'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        $mapping = new PaymentProviderOfferMapping;
        $mapping->forceFill([
            'organization_id' => $organization->getKey(),
            'gateway' => 'lava',
            'sellable_type' => Service::class,
            'sellable_id' => $product->getKey(),
            'currency' => 'RUB',
            'external_offer_id' => '836b9fc5-7ae9-4a27-9642-592bc44072b7',
            'is_active' => true,
        ])->save();
        $credential = OrganizationCredential::factory()->forOrganization($organization)->make([
            'provider' => 'lava',
            'credential_name' => 'default',
            'status' => CredentialStatus::Active->value,
        ]);
        $credential->forceFill(['credentials' => ['api_key' => 'lava-api-key']])->save();
        Http::fake([
            '*' => Http::response([
                'id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/course',
            ], 201),
        ]);

        $checkout = app(StartPurchaseCheckout::class)->onlineProduct(
            organization: $organization,
            client: $client,
            product: $product,
            gateway: 'lava',
            idempotencyKey: 'crm-course-purchase-1',
            buyerEmail: (string) $client->email,
        );
        $checkout->purchase->forceFill([
            'status' => 'paid',
            'paid_at' => now(),
        ])->save();
        $obligation = $checkout->purchase->obligation()->firstOrFail();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(ListFinancialObligations::class);
        $component->loadTable();
        $component
            ->assertTableColumnStateSet('service.name', 'Целительство', $obligation)
            ->assertTableColumnStateSet('fulfillment_status', 'Требуется выдача', $obligation)
            ->assertTableActionExists('fulfillPurchase', null, $obligation)
            ->mountTableAction('fulfillPurchase', $obligation)
            ->callMountedTableAction();

        self::assertSame('fulfilled', $checkout->purchase->items()->sole()->fulfillment->fresh()->status->value);
        self::assertDatabaseHas('commerce_fulfillment_events', [
            'fulfillment_id' => $checkout->purchase->items()->sole()->fulfillment->getKey(),
            'actor_user_id' => $admin->getKey(),
            'to_status' => 'fulfilled',
        ]);
        self::assertSame(1, FulfillmentEvent::query()->count());
    }

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(OrganizationContext::class)->set($organization);
    }
}
