<?php

namespace Tests\Feature;

use App\Filament\Pages\FinanceConfiguration;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\TrackerPlans\Pages\CreateTrackerPlan;
use App\Models\User;
use App\Modules\Commerce\Application\StartPurchaseCheckout;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Application\SaveLavaConfiguration;
use App\Modules\Finance\Application\SavePaymentProviderOfferMappings;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class LavaManagerConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_save_lava_credentials_without_rendering_secrets_and_blank_edit_preserves_them(): void
    {
        [$organization, $admin] = $this->financeFixture();
        app(SaveLavaConfiguration::class)->handle($admin, 'lava-api-key', 'lava-webhook-key', true);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(FinanceConfiguration::class)
            ->assertSuccessful()
            ->assertSet('data.lava_enabled', true)
            ->assertSet('data.lava_webhook_url', route('webhooks.lava'))
            ->assertSee('Подключена')
            ->assertSee('Укажите этот адрес в настройках webhook Lava.');

        self::assertStringNotContainsString('lava-api-key', $component->html());
        self::assertStringNotContainsString('lava-webhook-key', $component->html());

        $component
            ->set('data.lava_api_key', null)
            ->set('data.lava_webhook_key', null)
            ->call('save')
            ->assertNotified('Финансовые настройки сохранены');

        $credential = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->where('provider', 'lava')
            ->sole();

        self::assertSame(CredentialStatus::Active, $credential->status);
        self::assertSame('lava-api-key', $credential->credentials['api_key']);
        self::assertSame('lava-webhook-key', $credential->credentials['webhook_api_key']);
        self::assertStringNotContainsString(
            'lava-api-key',
            (string) DB::table('organization_credentials')->where('id', $credential->getKey())->value('credentials'),
        );
    }

    public function test_view_only_finance_user_cannot_change_lava_credentials(): void
    {
        [$organization, $admin] = $this->financeFixture();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        app(SaveLavaConfiguration::class)->handle($admin, 'lava-api-key', null, true);
        $this->resolveFilamentContext($staff, $organization);

        Livewire::actingAs($staff)
            ->test(FinanceConfiguration::class)
            ->assertSuccessful()
            ->assertFormFieldDisabled('lava_enabled')
            ->assertFormFieldDisabled('lava_api_key')
            ->assertFormFieldDisabled('lava_webhook_key');

        $this->expectException(AuthorizationException::class);
        app(SaveLavaConfiguration::class)->handle($staff, 'new-api-key', null, true);
    }

    public function test_active_lava_configuration_requires_an_api_key(): void
    {
        [, $admin] = $this->financeFixture();

        $this->expectException(ValidationException::class);
        app(SaveLavaConfiguration::class)->handle($admin, null, null, true);
    }

    public function test_manager_can_create_update_and_deactivate_service_lava_mapping_from_the_form(): void
    {
        [$organization, $admin] = $this->financeFixture();
        $this->enableServiceCatalog($organization);
        $this->resolveFilamentContext($admin, $organization);
        $firstOffer = '836b9fc5-7ae9-4a27-9642-592bc44072b7';
        $secondOffer = '7ea82675-4ded-4133-95a7-a6efbaf165cc';

        Livewire::actingAs($admin)
            ->test(CreateService::class)
            ->fillForm([
                'name' => 'Цифровой курс',
                'summary' => 'Онлайн-доступ к курсу.',
                'catalog_type' => CatalogItemType::OnlineProduct->value,
                'price' => '15000',
                'price_currency' => 'RUB',
                'is_active' => true,
                'lava_enabled' => true,
                'lava_offer_id' => $firstOffer,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $service = Service::query()->sole();
        $mapping = PaymentProviderOfferMapping::query()->sole();
        self::assertSame($organization->getKey(), $mapping->organization_id);
        self::assertSame(Service::class, $mapping->sellable_type);
        self::assertSame($service->getKey(), $mapping->sellable_id);
        self::assertTrue($mapping->is_active);
        self::assertSame($firstOffer, $mapping->external_offer_id);

        Livewire::actingAs($admin)
            ->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['lava_offer_id' => $secondOffer])
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame($secondOffer, PaymentProviderOfferMapping::query()->sole()->external_offer_id);

        Livewire::actingAs($admin)
            ->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['lava_enabled' => false])
            ->call('save')
            ->assertHasNoErrors();

        $mapping = PaymentProviderOfferMapping::query()->sole();
        self::assertFalse($mapping->is_active);
        self::assertSame($secondOffer, $mapping->external_offer_id);
    }

    public function test_tracker_plan_form_creates_a_mapping_for_the_immutable_current_version(): void
    {
        [$organization, $admin] = $this->financeFixture();
        $this->resolveFilamentContext($admin, $organization);
        $offer = '836b9fc5-7ae9-4a27-9642-592bc44072b7';

        Livewire::actingAs($admin)
            ->test(CreateTrackerPlan::class)
            ->fillForm([
                'name' => 'Трекер на 30 дней',
                'price' => '3000',
                'currency' => 'RUB',
                'duration_days' => 30,
                'description' => 'Доступ на 30 дней.',
                'included_access' => true,
                'display_order' => 1,
                'is_active' => true,
                'is_visible' => true,
                'lava_enabled' => true,
                'lava_offer_id' => $offer,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $plan = TrackerPlan::query()->sole();
        $version = $plan->currentVersion;
        self::assertInstanceOf(TrackerPlanVersion::class, $version);
        $mapping = PaymentProviderOfferMapping::query()->sole();
        self::assertSame(TrackerPlanVersion::class, $mapping->sellable_type);
        self::assertSame($version->getKey(), $mapping->sellable_id);
        self::assertSame($offer, $mapping->external_offer_id);
    }

    public function test_mapping_action_rejects_duplicate_or_disallowed_currency_and_deactivation_keeps_the_row(): void
    {
        [$organization, $admin] = $this->financeFixture();
        $service = Service::factory()->forOrganization($organization)->create([
            'catalog_type' => CatalogItemType::OnlineProduct->value,
            'price_minor' => 150000,
            'price_currency' => 'RUB',
        ]);
        $action = app(SavePaymentProviderOfferMappings::class);
        $mapping = [
            ['currency' => 'RUB', 'offer_id' => '836b9fc5-7ae9-4a27-9642-592bc44072b7'],
            ['currency' => 'RUB', 'offer_id' => '7ea82675-4ded-4133-95a7-a6efbaf165cc'],
        ];
        $this->resolveFilamentContext($admin, $organization);

        try {
            $action->handle($admin, Service::class, (int) $service->getKey(), true, $mapping);
            self::fail('Duplicate currency mappings must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('lava_offers.1.currency', $exception->errors());
        }

        try {
            $action->handle($admin, Service::class, (int) $service->getKey(), true, [[
                'currency' => 'RUB',
                'offer_id' => 'not-a-uuid',
            ]]);
            self::fail('An invalid Lava offer ID must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('lava_offers.0.offer_id', $exception->errors());
        }

        try {
            $action->handle($admin, Service::class, (int) $service->getKey(), true, [[
                'currency' => 'USD',
                'offer_id' => '836b9fc5-7ae9-4a27-9642-592bc44072b7',
            ]]);
            self::fail('A currency outside the finance configuration must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('lava_offers.0.currency', $exception->errors());
        }

        $action->handle($admin, Service::class, (int) $service->getKey(), true, [[
            'currency' => 'RUB',
            'offer_id' => '836b9fc5-7ae9-4a27-9642-592bc44072b7',
        ]]);
        $action->handle($admin, Service::class, (int) $service->getKey(), false, []);

        $saved = PaymentProviderOfferMapping::query()->sole();
        self::assertFalse($saved->is_active);
        self::assertSame('836b9fc5-7ae9-4a27-9642-592bc44072b7', $saved->external_offer_id);
    }

    public function test_mapping_action_rejects_a_sellable_from_another_organization(): void
    {
        [$organization, $admin] = $this->financeFixture();
        $otherOrganization = Organization::factory()->create();
        $service = Service::factory()->forOrganization($otherOrganization)->create([
            'catalog_type' => CatalogItemType::OnlineProduct->value,
            'price_minor' => 150000,
            'price_currency' => 'RUB',
        ]);
        $this->resolveFilamentContext($admin, $organization);

        $this->expectException(AuthorizationException::class);
        app(SavePaymentProviderOfferMappings::class)->handle(
            $admin,
            Service::class,
            (int) $service->getKey(),
            true,
            [['currency' => 'RUB', 'offer_id' => '836b9fc5-7ae9-4a27-9642-592bc44072b7']],
        );
    }

    public function test_checkout_uses_the_offer_mapping_created_by_the_manager_action(): void
    {
        [$organization, $admin] = $this->financeFixture();
        $product = Service::factory()->forOrganization($organization)->create([
            'name' => 'Целительство',
            'catalog_type' => CatalogItemType::OnlineProduct->value,
            'price_minor' => 150000,
            'price_currency' => 'RUB',
        ]);
        $client = Client::factory()->forOrganization($organization)->create();
        $this->resolveFilamentContext($admin, $organization);
        app(SavePaymentProviderOfferMappings::class)->handle(
            $admin,
            Service::class,
            (int) $product->getKey(),
            true,
            [['currency' => 'RUB', 'offer_id' => '836b9fc5-7ae9-4a27-9642-592bc44072b7']],
        );
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

        app(StartPurchaseCheckout::class)->onlineProduct(
            organization: $organization,
            client: $client,
            product: $product,
            gateway: 'lava',
            idempotencyKey: 'manager-mapping-checkout',
            buyerEmail: (string) $client->email,
        );

        Http::assertSent(function (Request $request): bool {
            return $request->data()['offerId'] === '836b9fc5-7ae9-4a27-9642-592bc44072b7';
        });
    }

    /** @return array{Organization, User} */
    private function financeFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'RUB',
            'allowed_currencies' => ['RUB'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
            'rates' => [],
        ]);

        return [$organization, $admin];
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->setOrganization($organization);
    }

    private function enableServiceCatalog(Organization $organization): void
    {
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
    }
}
