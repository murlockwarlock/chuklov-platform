<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Application\ApplyGiftCertificateToObligation;
use App\Modules\Commerce\Application\ClaimGiftCertificate;
use App\Modules\Commerce\Application\CorrectGiftCertificateRedemption;
use App\Modules\Commerce\Application\CreateGiftCertificateTransfer;
use App\Modules\Commerce\Application\GiftCertificateBalanceProjection;
use App\Modules\Commerce\Application\IssueGiftCertificate;
use App\Modules\Commerce\Application\StartPurchaseCheckout;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\GiftCertificateMovementType;
use App\Modules\Commerce\Domain\Models\GiftCertificate;
use App\Modules\Commerce\Domain\Models\GiftCertificateMovement;
use App\Modules\Commerce\Domain\Models\GiftCertificateRedemption;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\DrainPaymentGatewayEvents;
use App\Modules\Finance\Application\ReceiveLavaWebhook;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\RecordManualPayment;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class GiftCertificateTest extends TestCase
{
    use RefreshDatabase;

    private int $checkoutSequence = 0;

    public function test_fully_paid_purchase_issues_one_certificate_and_partial_payment_does_not(): void
    {
        [$organization, $admin, $client, $giftService] = $this->fixture();
        $partial = $this->checkout($organization, $client, $giftService, 'gift-partial');
        $obligation = $partial->purchase->obligation()->firstOrFail();

        app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '400.00',
            currency: 'USD',
            paymentMethod: PaymentMethod::Cash,
            occurredAt: now(),
            note: null,
            receipt: null,
            idempotencyKey: 'gift-partial-payment',
        );

        self::assertSame(0, GiftCertificate::query()->count());
        self::assertSame('pending_payment', $partial->purchase->refresh()->status->value);

        app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '600.00',
            currency: 'USD',
            paymentMethod: PaymentMethod::Cash,
            occurredAt: now(),
            note: null,
            receipt: null,
            idempotencyKey: 'gift-full-payment',
        );

        $certificate = GiftCertificate::query()->sole();

        self::assertSame(100_000, $certificate->original_amount_minor);
        self::assertSame($client->getKey(), $certificate->current_holder_client_id);
        self::assertSame(CommerceFulfillmentStatus::Fulfilled, $partial->purchase->items()->sole()->fulfillment->status);

        app(IssueGiftCertificate::class)->handle($partial->purchase->items()->sole()->fulfillment);

        self::assertSame(1, GiftCertificate::query()->count());
        self::assertSame(1, GiftCertificateMovement::query()->where('movement_type', GiftCertificateMovementType::Issued->value)->count());
    }

    public function test_manual_full_settlement_issues_certificate_without_gateway_completion(): void
    {
        [$organization, $admin, $client, $giftService] = $this->fixture();
        $checkout = $this->checkout($organization, $client, $giftService, 'gift-manual-full');

        app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $checkout->purchase->obligation()->firstOrFail(),
            amount: '1000.00',
            currency: 'USD',
            paymentMethod: PaymentMethod::BankTransfer,
            occurredAt: now(),
            note: null,
            receipt: null,
            idempotencyKey: 'gift-manual-full-payment',
        );

        self::assertSame('paid', $checkout->purchase->refresh()->status->value);
        self::assertSame(1, GiftCertificate::query()->count());
        self::assertSame(CommerceFulfillmentStatus::Fulfilled, $checkout->purchase->items()->sole()->fulfillment->refresh()->status);
    }

    public function test_transfer_claim_is_single_use_hashed_and_organization_scoped(): void
    {
        [$organization, $admin, $client, $giftService] = $this->fixture();
        $checkout = $this->checkout($organization, $client, $giftService, 'gift-transfer');
        $this->settle($organization, $checkout->transaction->provider_reference, 1000.00, 'gift-transfer');
        $certificate = GiftCertificate::query()->sole();
        $recipient = Client::factory()->forOrganization($organization)->create();
        $transfer = app(CreateGiftCertificateTransfer::class)->handle($client, $certificate);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $transfer->rawToken);
        self::assertNotSame($transfer->rawToken, $transfer->claim->token_hash);
        self::assertSame(hash('sha256', $transfer->rawToken), $transfer->claim->token_hash);

        $claimed = app(ClaimGiftCertificate::class)->handle($recipient, $transfer->rawToken);
        self::assertSame($recipient->getKey(), $claimed->current_holder_client_id);
        self::assertSame('claimed', $transfer->claim->refresh()->status);

        try {
            app(ClaimGiftCertificate::class)->handle($client, $transfer->rawToken);
            self::fail('The claim token should be single-use.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('token', $exception->errors());
        }

        $otherOrganization = Organization::factory()->create();
        $otherRecipient = Client::factory()->forOrganization($otherOrganization)->create();
        $this->setOrganization($otherOrganization);

        try {
            app(ClaimGiftCertificate::class)->handle($otherRecipient, $transfer->rawToken);
            self::fail('A claim token must not cross organization boundaries.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('token', $exception->errors());
        }
    }

    public function test_guest_claim_page_preserves_deep_link_for_authenticated_claim(): void
    {
        [$organization, , $client, $giftService] = $this->fixture();
        $checkout = $this->checkout($organization, $client, $giftService, 'gift-portal-claim');
        $this->settle($organization, $checkout->transaction->provider_reference, 1000.00, 'gift-portal-claim');
        $certificate = GiftCertificate::query()->sole();
        $recipient = Client::factory()->forOrganization($organization)->create();
        $transfer = app(CreateGiftCertificateTransfer::class)->handle($client, $certificate);

        $this->get(route('gift-certificates.claim', ['token' => $transfer->rawToken]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/GiftCertificateClaim')
                ->where('authenticated', false)
                ->where('certificate.originalAmountMinor', 100000));

        self::assertSame($transfer->rawToken, session('gift_certificate_claim_token'));

        $this->withSession(['client_portal.client_id' => $recipient->getKey()])
            ->post(route('gift-certificates.claim.submit', ['token' => $transfer->rawToken]))
            ->assertRedirect(route('portal.gift-certificates.index'));

        self::assertSame($recipient->getKey(), $certificate->refresh()->current_holder_client_id);
    }

    public function test_partial_full_idempotent_redemption_and_correction_restore_both_balances(): void
    {
        [$organization, $admin, $client, $giftService] = $this->fixture();
        $checkout = $this->checkout($organization, $client, $giftService, 'gift-redemption');
        $this->settle($organization, $checkout->transaction->provider_reference, 1000.00, 'gift-redemption');
        $certificate = GiftCertificate::query()->sole();
        $obligation = $this->bookingObligation($organization, $admin, $client, 30000);

        $first = app(ApplyGiftCertificateToObligation::class)->handle(
            client: $client,
            certificate: $certificate,
            obligationId: $obligation->getKey(),
            amount: '200.00',
            currency: 'USD',
            idempotencyKey: 'gift-redeem-first',
        );
        $replayed = app(ApplyGiftCertificateToObligation::class)->handle(
            client: $client,
            certificate: $certificate,
            obligationId: $obligation->getKey(),
            amount: '200.00',
            currency: 'USD',
            idempotencyKey: 'gift-redeem-first',
        );

        self::assertSame($first->getKey(), $replayed->getKey());
        self::assertSame(80000, app(GiftCertificateBalanceProjection::class)->balance($certificate->refresh())->minorUnits());
        self::assertSame(10000, app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey())->outstanding->minorUnits());

        $second = app(ApplyGiftCertificateToObligation::class)->handle(
            client: $client,
            certificate: $certificate,
            obligationId: $obligation->getKey(),
            amount: '100.00',
            currency: 'USD',
            idempotencyKey: 'gift-redeem-second',
        );
        self::assertSame(70000, app(GiftCertificateBalanceProjection::class)->balance($certificate->refresh())->minorUnits());
        self::assertTrue(app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey())->isSettled());

        $correction = app(CorrectGiftCertificateRedemption::class)->handle(
            actor: $admin,
            entry: $second,
            reason: 'Отмена списания сертификата.',
            idempotencyKey: 'gift-correction',
        );

        self::assertSame(-10000, $correction->settlement_amount_minor);
        self::assertSame(80000, app(GiftCertificateBalanceProjection::class)->balance($certificate->refresh())->minorUnits());
        self::assertSame(10000, app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey())->outstanding->minorUnits());
        self::assertSame(2, GiftCertificateRedemption::query()->count());
        self::assertSame(3, FinancialLedgerEntry::query()->where('obligation_id', $obligation->getKey())->count());
        self::assertSame(1, GiftCertificateMovement::query()->where('movement_type', GiftCertificateMovementType::RedemptionReversed->value)->count());
        self::assertSame(FinancialLedgerEntryType::GiftCertificateRedemption, $first->refresh()->entry_type);
    }

    public function test_redemption_limits_currency_and_gift_certificate_purchase_eligibility(): void
    {
        [$organization, $admin, $client, $giftService] = $this->fixture();
        $checkout = $this->checkout($organization, $client, $giftService, 'gift-limits');
        $this->settle($organization, $checkout->transaction->provider_reference, 1000.00, 'gift-limits');
        $certificate = GiftCertificate::query()->sole();
        $obligation = $this->bookingObligation($organization, $admin, $client, 30000);

        foreach ([
            ['amount' => '301.00', 'currency' => 'USD', 'key' => 'gift-too-much-debt'],
            ['amount' => '1.00', 'currency' => 'RUB', 'key' => 'gift-wrong-currency'],
        ] as $attempt) {
            try {
                app(ApplyGiftCertificateToObligation::class)->handle(
                    client: $client,
                    certificate: $certificate,
                    obligationId: $obligation->getKey(),
                    amount: $attempt['amount'],
                    currency: $attempt['currency'],
                    idempotencyKey: $attempt['key'],
                );
                self::fail('The invalid certificate redemption should be rejected.');
            } catch (ValidationException $exception) {
                self::assertNotSame([], $exception->errors());
            }
        }

        $secondCheckout = $this->checkout($organization, $client, $giftService, 'gift-second-purchase');
        $secondObligation = $secondCheckout->purchase->obligation()->firstOrFail();
        try {
            app(ApplyGiftCertificateToObligation::class)->handle(
                client: $client,
                certificate: $certificate,
                obligationId: $secondObligation->getKey(),
                amount: '10.00',
                currency: 'USD',
                idempotencyKey: 'gift-cannot-buy-gift',
            );
            self::fail('A gift certificate must not buy another gift certificate.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('obligation', $exception->errors());
        }
    }

    public function test_cross_organization_redemption_is_rejected(): void
    {
        [$organization, $admin, $client, $giftService] = $this->fixture();
        $checkout = $this->checkout($organization, $client, $giftService, 'gift-cross-org');
        $this->settle($organization, $checkout->transaction->provider_reference, 1000.00, 'gift-cross-org');
        $certificate = GiftCertificate::query()->sole();
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create(['timezone' => 'UTC']);
        $this->setOrganization($otherOrganization);
        app(SaveCurrencyConfiguration::class)->handle($otherAdmin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        $obligation = $this->bookingObligation($otherOrganization, $otherAdmin, $otherClient, 30000);

        try {
            app(ApplyGiftCertificateToObligation::class)->handle(
                client: $otherClient,
                certificate: $certificate,
                obligationId: $obligation->getKey(),
                amount: '1.00',
                currency: 'USD',
                idempotencyKey: 'gift-cross-org-redemption',
            );
            self::fail('A certificate must not be redeemed across organizations.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $credential = OrganizationCredential::factory()->forOrganization($organization)->make([
            'provider' => 'lava',
            'credential_name' => 'default',
            'status' => CredentialStatus::Active->value,
        ]);
        $credential->forceFill([
            'credentials' => ['api_key' => 'lava-api-key', 'webhook_api_key' => 'lava-webhook-key'],
        ])->save();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $giftService = Service::factory()->forOrganization($organization)->create([
            'name' => 'Подарочный сертификат',
            'catalog_type' => CatalogItemType::GiftCertificate->value,
            'price_minor' => 100000,
            'price_currency' => 'USD',
        ]);
        $mapping = new PaymentProviderOfferMapping;
        $mapping->forceFill([
            'organization_id' => $organization->getKey(),
            'gateway' => 'lava',
            'sellable_type' => Service::class,
            'sellable_id' => $giftService->getKey(),
            'currency' => 'USD',
            'external_offer_id' => '836b9fc5-7ae9-4a27-9642-592bc44072b7',
            'is_active' => true,
        ])->save();
        $this->setOrganization($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        Http::fake(function () {
            $this->checkoutSequence++;
            $key = 'gift-sequence-'.$this->checkoutSequence;

            return Http::response([
                'id' => $this->uuidFor($key),
                'status' => 'in-progress',
                'paymentUrl' => 'https://pay.lava.top/gift-certificate',
            ], 201);
        });

        return [$organization, $admin, $client, $giftService];
    }

    private function checkout(Organization $organization, Client $client, Service $service, string $key): mixed
    {
        return app(StartPurchaseCheckout::class)->giftCertificate(
            organization: $organization,
            client: $client,
            product: $service,
            gateway: 'lava',
            idempotencyKey: $key,
            buyerEmail: (string) $client->email,
        );
    }

    private function settle(Organization $organization, string $providerReference, float $amount, string $suffix): void
    {
        app(ReceiveLavaWebhook::class)->handle($organization->getKey(), [
            'eventType' => 'payment.success',
            'contractId' => $providerReference,
            'buyer' => ['email' => $suffix.'@example.com'],
            'amount' => $amount,
            'currency' => 'USD',
            'timestamp' => '2026-10-08T10:00:00Z',
            'status' => 'completed',
        ]);
        app(DrainPaymentGatewayEvents::class)->handle($organization->getKey(), 'lava', $providerReference);
    }

    private function bookingObligation(Organization $organization, User $admin, Client $client, int $amountMinor): FinancialObligation
    {
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => $amountMinor,
            'price_currency' => 'USD',
        ]);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => now()->subHours(2),
                'ends_at' => now()->subHour(),
                'blocking_ends_at' => now()->subHour(),
            ]);
        $completed = app(CompleteBooking::class)->handle($admin, $booking);

        return FinancialObligation::query()->where('booking_id', $completed->getKey())->firstOrFail();
    }

    private function uuidFor(string $value): string
    {
        $hash = md5($value);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }
}
