<?php

namespace Tests\Feature;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Filament\Resources\FinancialObligations\Pages\ViewFinancialObligation;
use App\Filament\Resources\GiftCertificates\Pages\ListGiftCertificates;
use App\Models\User;
use App\Modules\Commerce\Application\CreateCrmGiftCertificateSale;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Finance\Application\RecordManualPayment;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GiftCertificateCrmSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ru');
    }

    #[DataProvider('fullPaymentMethods')]
    public function test_crm_sale_uses_finance_settlement_and_issues_one_certificate(PaymentMethod $paymentMethod): void
    {
        [$organization, $admin, $client, $product] = $this->fixture();
        $obligation = app(CreateCrmGiftCertificateSale::class)->handle(
            actor: $admin,
            clientId: $client->getKey(),
            productId: $product->getKey(),
            idempotencyKey: 'crm-sale-'.$paymentMethod->value,
        );

        self::assertSame(0, GiftCertificate::query()->count());
        self::assertSame(PurchaseStatus::PendingPayment, $obligation->purchase()->firstOrFail()->status);
        self::assertSame('gift_certificate', $obligation->purchase->items()->sole()->fulfillment_provider);
        self::assertSame(CommerceFulfillmentStatus::Pending, $obligation->purchase->items()->sole()->fulfillment->status);

        app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '100.00',
            currency: 'USD',
            paymentMethod: $paymentMethod,
            occurredAt: now(),
            note: $paymentMethod === PaymentMethod::Barter ? 'Рекламная интеграция' : null,
            receipt: null,
            idempotencyKey: 'crm-sale-payment-'.$paymentMethod->value,
        );

        $purchase = Purchase::query()->whereKey($obligation->purchase_id)->firstOrFail();
        self::assertSame(PurchaseStatus::Paid, $purchase->status);
        self::assertSame(1, GiftCertificate::query()->count());
        self::assertSame(1, GiftCertificate::query()->where('purchase_id', $purchase->getKey())->count());
        self::assertSame(CommerceFulfillmentStatus::Fulfilled, $purchase->items()->sole()->fulfillment->refresh()->status);
        self::assertSame($client->getKey(), GiftCertificate::query()->sole()->current_holder_client_id);
        self::assertSame($organization->getKey(), $obligation->organization_id);
    }

    public static function fullPaymentMethods(): array
    {
        return [
            'cash' => [PaymentMethod::Cash],
            'card' => [PaymentMethod::ManualCard],
            'barter' => [PaymentMethod::Barter],
        ];
    }

    public function test_crm_sale_partial_payment_does_not_issue_certificate_until_the_obligation_is_settled(): void
    {
        [, $admin, $client, $product] = $this->fixture();
        $obligation = app(CreateCrmGiftCertificateSale::class)->handle($admin, $client->getKey(), $product->getKey(), 'crm-partial-sale');

        app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '40.00',
            currency: 'USD',
            paymentMethod: PaymentMethod::Cash,
            occurredAt: now(),
            note: null,
            receipt: null,
            idempotencyKey: 'crm-partial-cash',
        );

        self::assertSame(0, GiftCertificate::query()->count());
        self::assertSame(PurchaseStatus::PendingPayment, Purchase::query()->findOrFail($obligation->purchase_id)->status);

        app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '60.00',
            currency: 'USD',
            paymentMethod: PaymentMethod::Barter,
            occurredAt: now(),
            note: 'Рекламная интеграция',
            receipt: null,
            idempotencyKey: 'crm-partial-barter',
        );

        self::assertSame(1, GiftCertificate::query()->count());
        self::assertSame(2, FinancialLedgerEntry::query()->where('obligation_id', $obligation->getKey())->count());
    }

    public function test_crm_sale_retry_with_the_same_intent_key_returns_one_purchase(): void
    {
        [$organization, $admin, $client, $product] = $this->fixture();
        $first = app(CreateCrmGiftCertificateSale::class)->handle($admin, $client->getKey(), $product->getKey(), 'crm-sale-retry');
        $second = app(CreateCrmGiftCertificateSale::class)->handle($admin, $client->getKey(), $product->getKey(), 'crm-sale-retry');

        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame(1, Purchase::query()
            ->where('organization_id', $organization->getKey())
            ->where('checkout_idempotency_key', 'crm-sale-retry')
            ->count());
        self::assertSame(1, FinancialObligation::query()->where('purchase_id', $first->purchase_id)->count());
    }

    public function test_finance_view_refreshes_to_issued_certificate_after_full_manual_payment(): void
    {
        [$organization, $admin, $client, $product] = $this->fixture();
        $obligation = app(CreateCrmGiftCertificateSale::class)->handle($admin, $client->getKey(), $product->getKey(), 'crm-immediate-refresh');
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(ViewFinancialObligation::class, ['record' => $obligation->getRouteKey()])
            ->mountAction('recordPayment')
            ->setActionData([
                'amount' => '100.00',
                'payment_method' => PaymentMethod::Cash->value,
                'occurred_at' => now()->format('Y-m-d H:i'),
                'idempotency_key' => 'crm-immediate-refresh-payment',
            ])
            ->callMountedAction()
            ->assertNotified('Оплата записана. Остаток обновлён.')
            ->assertSee('Сертификат выпущен')
            ->assertSee('Открыть сертификат');
    }

    public function test_crm_finance_certificate_action_creates_pending_sale_without_lava(): void
    {
        [$organization, $admin, $client, $product] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(ListGiftCertificates::class)
            ->assertSuccessful()
            ->assertActionExists('sellGiftCertificate')
            ->mountAction('sellGiftCertificate')
            ->assertActionMounted('sellGiftCertificate')
            ->assertSee('Продать сертификат');

        $component
            ->setActionData([
                'client_id' => $client->getKey(),
                'product_id' => $product->getKey(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $obligation = FinancialObligation::query()->where('organization_id', $organization->getKey())->sole();
        $component->assertRedirect(FinancialObligationResource::getUrl('view', ['record' => $obligation->getKey()]));
        self::assertSame(0, GiftCertificate::query()->count());
        self::assertSame(PurchaseStatus::PendingPayment, $obligation->purchase->status);
    }

    public function test_crm_certificate_action_shows_catalog_empty_state(): void
    {
        [$organization, $admin] = $this->fixture(withProduct: false);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(ListGiftCertificates::class)
            ->mountAction('sellGiftCertificate');

        self::assertStringContainsString('Сначала добавьте подарочный сертификат в Каталоге услуг.', $component->getMountedActionModalHtml());
        self::assertStringContainsString('Перейти в Каталог услуг', $component->getMountedActionModalHtml());
    }

    public function test_crm_sale_revalidates_permission_tenant_catalog_type_and_active_state(): void
    {
        [$organization, $admin, $client, $product] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();

        $this->assertSaleValidationFailure(
            fn (): FinancialObligation => app(CreateCrmGiftCertificateSale::class)->handle(
                $admin,
                $otherClient->getKey(),
                $product->getKey(),
                'crm-cross-org-client',
            ),
        );

        $nonGiftProduct = Service::factory()->forOrganization($organization)->create([
            'catalog_type' => CatalogItemType::Service->value,
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        $this->assertSaleValidationFailure(
            fn (): FinancialObligation => app(CreateCrmGiftCertificateSale::class)->handle(
                $admin,
                $client->getKey(),
                $nonGiftProduct->getKey(),
                'crm-non-gift',
            ),
        );

        $product->forceFill(['is_active' => false])->save();
        $this->assertSaleValidationFailure(
            fn (): FinancialObligation => app(CreateCrmGiftCertificateSale::class)->handle(
                $admin,
                $client->getKey(),
                $product->getKey(),
                'crm-inactive',
            ),
        );

        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        try {
            app(CreateCrmGiftCertificateSale::class)->handle($staff, $client->getKey(), $product->getKey(), 'crm-no-permission');
            self::fail('A staff user without finance manage permission must be rejected.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    /** @return array{0: Organization, 1: User, 2?: Client, 3?: Service} */
    private function fixture(bool $withProduct = true): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);

        if (! $withProduct) {
            return [$organization, $admin];
        }

        $client = Client::factory()->forOrganization($organization)->create();
        $product = Service::factory()->forOrganization($organization)->create([
            'name' => 'Подарочный сертификат 100 USD',
            'catalog_type' => CatalogItemType::GiftCertificate->value,
            'price_minor' => 10000,
            'price_currency' => 'USD',
            'is_active' => true,
        ]);

        return [$organization, $admin, $client, $product];
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

    /** @param Closure(): FinancialObligation $operation */
    private function assertSaleValidationFailure(Closure $operation): void
    {
        try {
            $operation();
            self::fail('The invalid CRM certificate sale must be rejected.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }
}
