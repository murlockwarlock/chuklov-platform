<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\FinancialObligations\Pages\ListFinancialObligations;
use App\Models\User;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\RecordFinancialSettlementEvent;
use App\Modules\Finance\Application\RecordManualPayment;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialLedgerEntryType;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\ApplyReferralCreditForStaff;
use App\Modules\Referrals\Application\ApplyReferralCreditToObligation;
use App\Modules\Referrals\Application\ConsumeFinanceSettlementEvent;
use App\Modules\Referrals\Application\CreditManualReferralBonus;
use App\Modules\Referrals\Application\GetReferralPartnerOverview;
use App\Modules\Referrals\Application\ReferralRewardBalanceProjection;
use App\Modules\Referrals\Application\RequestReferralPayout;
use App\Modules\Referrals\Application\RestoreReferralCredit;
use App\Modules\Referrals\Application\SaveReferralRewardProgram;
use App\Modules\Referrals\Domain\Enums\ReferralRewardCategory;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ReferralCreditRedemptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordinary_client_can_fully_pay_a_booking_with_service_credit(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'booking-full', '10.00');
        $obligation = $this->bookingObligation($organization, $client, 'booking-credit-full', 1000);

        $entry = app(ApplyReferralCreditToObligation::class)->handle(
            client: $client,
            obligationId: $obligation->getKey(),
            amount: '10.00',
            currency: 'USD',
            idempotencyKey: 'credit-booking-full',
        );

        self::assertSame(FinancialLedgerEntryType::ReferralCredit, $entry->entry_type);
        self::assertSame(PaymentMethod::ReferralCredit, $entry->payment_method);
        self::assertSame('referral', $entry->source->value);
        self::assertSame(0, app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey())
            ->outstanding
            ->minorUnits());
        self::assertSame(0, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
        self::assertDatabaseHas('referral_reward_ledger_entries', [
            'financial_ledger_entry_id' => $entry->getKey(),
            'entry_type' => ReferralRewardLedgerEntryType::Redeemed->value,
            'reward_category' => ReferralRewardCategory::ServiceCredit->value,
            'amount_minor' => 1000,
        ]);

        $retry = app(ApplyReferralCreditToObligation::class)->handle(
            client: $client,
            obligationId: $obligation->getKey(),
            amount: '10.00',
            currency: 'USD',
            idempotencyKey: 'credit-booking-full',
        );

        self::assertSame($entry->getKey(), $retry->getKey());
        self::assertSame(1, FinancialLedgerEntry::query()->where('entry_type', FinancialLedgerEntryType::ReferralCredit->value)->count());
        self::assertDatabaseHas('integration_events', [
            'aggregate_type' => 'financial_obligation',
            'aggregate_id' => $obligation->getKey(),
            'event_type' => IntegrationEventType::FinanceObligationSettled->value,
        ]);
    }

    public function test_partial_referral_credit_can_be_combined_with_regular_payment(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'booking-partial', '5.00');
        $obligation = $this->bookingObligation($organization, $client, 'booking-credit-partial', 1500);

        app(ApplyReferralCreditToObligation::class)->handle(
            client: $client,
            obligationId: $obligation->getKey(),
            amount: '5.00',
            currency: 'USD',
            idempotencyKey: 'credit-booking-partial',
        );
        app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '10.00',
            currency: 'USD',
            paymentMethod: PaymentMethod::Cash,
            occurredAt: CarbonImmutable::now(),
            note: 'Остаток после бонуса',
            receipt: null,
            idempotencyKey: 'cash-after-credit',
        );

        $reconciliation = app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey());
        self::assertTrue($reconciliation->isSettled());
        self::assertSame([
            FinancialLedgerEntryType::ReferralCredit->value,
            FinancialLedgerEntryType::ManualPayment->value,
        ], FinancialLedgerEntry::query()
            ->where('obligation_id', $obligation->getKey())
            ->orderBy('id')
            ->get()
            ->map(static fn (FinancialLedgerEntry $entry): string => $entry->entry_type->value)
            ->all());
    }

    public function test_authorized_crm_staff_can_apply_partial_credit_with_actor_audit_and_restore_it(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'crm-credit', '10.00');
        $obligation = $this->bookingObligation($organization, $client, 'crm-credit-obligation', 2000);

        $entry = app(ApplyReferralCreditForStaff::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '3.00',
            idempotencyKey: 'crm-credit-apply',
        );

        self::assertSame($admin->getKey(), $entry->actor_user_id);
        self::assertSame(1700, app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey())
            ->outstanding
            ->minorUnits());
        self::assertSame(700, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
        self::assertDatabaseHas('audit_events', [
            'action' => 'finance.referral_credit.applied',
            'actor_user_id' => $admin->getKey(),
            'target_id' => (string) $entry->getKey(),
        ]);
        self::assertSame('crm', DB::table('audit_events')
            ->where('action', 'finance.referral_credit.applied')
            ->where('target_id', (string) $entry->getKey())
            ->value('metadata->source'));

        $retry = app(ApplyReferralCreditForStaff::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '3.00',
            idempotencyKey: 'crm-credit-apply',
        );

        self::assertSame($entry->getKey(), $retry->getKey());
        self::assertSame(1, FinancialLedgerEntry::query()
            ->where('obligation_id', $obligation->getKey())
            ->where('entry_type', FinancialLedgerEntryType::ReferralCredit->value)
            ->count());
        self::assertSame(1, DB::table('audit_events')
            ->where('action', 'finance.referral_credit.applied')
            ->where('target_id', (string) $entry->getKey())
            ->count());

        try {
            app(ApplyReferralCreditForStaff::class)->handle(
                actor: $admin,
                obligation: $obligation,
                amount: '4.00',
                idempotencyKey: 'crm-credit-apply',
            );
            self::fail('A reused CRM idempotency key must reject changed parameters.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $correction = app(RestoreReferralCredit::class)->handle(
            actor: $admin,
            entry: $entry,
            reason: 'Возврат бонусной оплаты.',
            idempotencyKey: 'crm-credit-restore',
        );

        self::assertSame($admin->getKey(), $correction->actor_user_id);
        self::assertSame(2000, app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey())
            ->outstanding
            ->minorUnits());
        self::assertSame(1000, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
    }

    public function test_crm_finance_action_shows_bonus_balance_and_applies_partial_credit(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'crm-action', '10.00');
        $obligation = $this->bookingObligation($organization, $client, 'crm-action-obligation', 2000);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(ListFinancialObligations::class);

        Livewire::actingAs($admin)
            ->test(ViewBooking::class, ['record' => $obligation->booking->getRouteKey()])
            ->assertSuccessful()
            ->assertActionExists('applyBookingReferralCredit');

        $component
            ->assertTableActionExists('applyReferralCredit', null, $obligation)
            ->mountTableAction('applyReferralCredit', $obligation)
            ->assertFormFieldExists('client_summary')
            ->assertFormFieldExists('service_summary')
            ->assertFormFieldExists('remaining_summary')
            ->assertFormFieldExists('available_summary')
            ->assertFormFieldExists('amount')
            ->assertFormFieldExists('currency_summary')
            ->assertFormFieldHidden('equivalent_summary')
            ->assertTableActionDataSet([
                'client_summary' => $client->full_name,
                'remaining_summary' => '20.00 USD',
                'available_summary' => '10.00 USD',
                'amount' => '10.00',
                'currency_summary' => 'USD',
            ])
            ->setTableActionData([
                'amount' => '3.00',
                'idempotency_key' => 'crm-action-partial',
            ])
            ->callMountedTableAction();

        self::assertSame(1700, app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey())
            ->outstanding
            ->minorUnits());

        app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '17.00',
            currency: 'USD',
            paymentMethod: PaymentMethod::Cash,
            occurredAt: CarbonImmutable::now(),
            note: 'Остаток после бонусов.',
            receipt: null,
            idempotencyKey: 'crm-action-remaining-payment',
        );

        self::assertTrue(app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey())
            ->isSettled());
    }

    public function test_crm_cross_currency_referral_credit_uses_base_balance_and_settlement_input(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'crm-cross-currency', '100.00');
        $this->configureMultiCurrency($organization, $admin, '85');
        $obligation = $this->bookingObligation($organization, $client, 'crm-cross-currency-obligation', 850000, 'RUB');
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(ListFinancialObligations::class)
            ->mountTableAction('applyReferralCredit', $obligation)
            ->assertFormFieldExists('equivalent_summary')
            ->assertTableActionDataSet([
                'available_summary' => '100.00 USD',
                'equivalent_summary' => '8500.00 RUB',
                'currency_summary' => 'RUB',
                'amount' => '8500.00',
            ])
            ->setTableActionData([
                'amount' => '5000.00',
                'idempotency_key' => 'crm-cross-currency-action',
            ])
            ->callMountedTableAction();

        unset($component);
        $entry = FinancialLedgerEntry::query()->where('entry_type', FinancialLedgerEntryType::ReferralCredit->value)->sole();

        self::assertSame(5882, $entry->amount_minor);
        self::assertSame('USD', $entry->currency->value);
        self::assertSame(500000, $entry->settlement_amount_minor);
        self::assertSame('RUB', $entry->settlement_currency->value);
    }

    public function test_crm_referral_credit_action_is_hidden_without_service_credit(): void
    {
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $obligation = $this->bookingObligation($organization, $client, 'crm-action-empty', 2000);
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(ListFinancialObligations::class)
            ->assertTableActionHidden('applyReferralCredit', $obligation);
    }

    public function test_crm_referral_credit_requires_finance_manage_permission_and_service_credit_category(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'crm-authorization', '10.00');
        $obligation = $this->bookingObligation($organization, $client, 'crm-authorization-obligation', 2000);
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();

        $this->expectException(AuthorizationException::class);
        app(ApplyReferralCreditForStaff::class)->handle(
            actor: $staff,
            obligation: $obligation,
            amount: '1.00',
            idempotencyKey: 'crm-unauthorized',
        );
    }

    public function test_crm_referral_credit_supports_full_purchase_obligation_redemption(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'crm-purchase', '6.00');
        $obligation = $this->purchaseObligation($organization, $client, 'crm-purchase-obligation', 600);

        $entry = app(ApplyReferralCreditForStaff::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '6.00',
            idempotencyKey: 'crm-purchase-credit',
        );

        self::assertSame($admin->getKey(), $entry->actor_user_id);
        self::assertTrue(app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey())
            ->isSettled());
        self::assertSame(0, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
    }

    public function test_crm_redemption_rejects_partner_cash_and_foreign_obligations(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $profile = app(ActivateReferralPartner::class)->handle($client, 'crm', $admin);
        app(CreditManualReferralBonus::class)->handle(
            actor: $admin,
            partner: $profile,
            amount: '10.00',
            currency: 'USD',
            reason: 'Партнёрский денежный бонус.',
            comment: null,
            idempotencyKey: 'crm-partner-cash',
        );
        $obligation = $this->bookingObligation($organization, $client, 'crm-partner-cash-obligation', 2000);

        try {
            app(ApplyReferralCreditForStaff::class)->handle(
                actor: $admin,
                obligation: $obligation,
                amount: '1.00',
                idempotencyKey: 'crm-partner-cash-reject',
            );
            self::fail('PartnerCash must not be spendable as service credit.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('amount', $exception->errors());
        }

        $foreignOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignAdmin = User::factory()->forOrganization($foreignOrganization)->create();
        app(OrganizationContext::class)->set($foreignOrganization);
        app(SaveCurrencyConfiguration::class)->handle($foreignAdmin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
            'rates' => [],
        ]);
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        $foreignObligation = $this->bookingObligation($foreignOrganization, $foreignClient, 'crm-foreign-obligation', 1000);
        app(OrganizationContext::class)->set($organization);

        $this->expectException(AuthorizationException::class);
        app(ApplyReferralCreditForStaff::class)->handle(
            actor: $admin,
            obligation: $foreignObligation,
            amount: '1.00',
            idempotencyKey: 'crm-foreign-obligation',
        );
    }

    public function test_portal_finance_exposes_and_applies_referral_credit_to_owned_obligation(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'portal-credit', '10.00');
        $obligation = $this->bookingObligation($organization, $client, 'portal-credit-obligation', 1000);
        $session = ['client_portal.client_id' => $client->getKey()];

        $this->withSession($session)
            ->get(route('portal.finance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('obligations.0.referralCredit.availableMinor', 1000)
                ->where('obligations.0.referralCredit.currency', 'USD')
                ->where('obligations.0.referralCredit.applyUrl', route('portal.finance.referral-credit.apply', $obligation->getKey())));

        $this->withSession($session)
            ->post(route('portal.finance.referral-credit.apply', $obligation->getKey()), [
                'amount' => '10.00',
                'currency' => 'USD',
                'idempotency_key' => 'portal-credit-request',
            ])
            ->assertRedirect(route('portal.finance.index'))
            ->assertSessionHasNoErrors();

        self::assertSame(0, app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey())
            ->outstanding
            ->minorUnits());
    }

    public function test_full_referral_credit_payment_preserves_settlement_evidence_for_the_client_referrer(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $this->relationship($organization, $referrer, $client);
        $this->earnServiceCredit($organization, $admin, $client, 'credit-source', '10.00');
        $obligation = $this->bookingObligation($organization, $client, 'credit-settlement-evidence', 1000);

        app(ApplyReferralCreditToObligation::class)->handle(
            client: $client,
            obligationId: $obligation->getKey(),
            amount: '10.00',
            currency: 'USD',
            idempotencyKey: 'credit-settlement-evidence',
        );

        $event = IntegrationEvent::query()
            ->where('event_type', IntegrationEventType::FinanceObligationSettled->value)
            ->where('aggregate_id', $obligation->getKey())
            ->firstOrFail();
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());

        self::assertDatabaseHas('referral_reward_ledger_entries', [
            'beneficiary_client_id' => $referrer->getKey(),
            'entry_type' => ReferralRewardLedgerEntryType::Earned->value,
            'reward_category' => ReferralRewardCategory::ServiceCredit->value,
        ]);
    }

    public function test_portal_cross_currency_referral_credit_shows_base_balance_and_settlement_equivalent(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'portal-cross-currency', '100.00');
        $this->configureMultiCurrency($organization, $admin, '85');
        $obligation = $this->bookingObligation($organization, $client, 'portal-cross-currency-obligation', 850000, 'RUB');
        $session = ['client_portal.client_id' => $client->getKey()];

        $this->withSession($session)
            ->get(route('portal.finance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('obligations.0.referralCredit.availableMinor', 850000)
                ->where('obligations.0.referralCredit.currency', 'RUB')
                ->where('obligations.0.referralCredit.baseAvailableMinor', 10000)
                ->where('obligations.0.referralCredit.baseCurrency', 'USD')
                ->where('obligations.0.referralCredit.outstandingMinor', 850000)
                ->where('obligations.0.referralCredit.conversionAvailable', true));

        $this->withSession($session)
            ->post(route('portal.finance.referral-credit.apply', $obligation->getKey()), [
                'amount' => '5000.00',
                'currency' => 'RUB',
                'idempotency_key' => 'portal-cross-currency-request',
            ])
            ->assertRedirect(route('portal.finance.index'))
            ->assertSessionHasNoErrors();

        self::assertSame(5882, FinancialLedgerEntry::query()
            ->where('entry_type', FinancialLedgerEntryType::ReferralCredit->value)
            ->sole()
            ->amount_minor);
    }

    public function test_purchase_obligation_uses_the_same_referral_credit_flow(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'purchase-credit', '6.00');
        $obligation = $this->purchaseObligation($organization, $client, 'purchase-credit-obligation', 600);

        $entry = app(ApplyReferralCreditToObligation::class)->handle(
            client: $client,
            obligationId: $obligation->getKey(),
            amount: '6.00',
            currency: 'USD',
            idempotencyKey: 'credit-purchase-full',
        );

        self::assertSame($obligation->purchase_id, $obligation->refresh()->purchase_id);
        self::assertNull($obligation->booking_id);
        self::assertNull($obligation->service_id);
        self::assertSame($obligation->getKey(), $entry->obligation_id);
        self::assertSame(0, app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey())
            ->outstanding
            ->minorUnits());
    }

    public function test_redemption_rejects_overspend_wrong_currency_and_foreign_client(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'credit-boundaries', '10.00');
        $obligation = $this->bookingObligation($organization, $client, 'credit-boundaries-obligation', 2000);

        try {
            app(ApplyReferralCreditToObligation::class)->handle($client, $obligation->getKey(), '10.01', 'USD', 'credit-too-large');
            self::fail('Referral credit must not overspend the available balance.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('amount', $exception->errors());
        }

        try {
            app(ApplyReferralCreditToObligation::class)->handle($client, $obligation->getKey(), '1.00', 'EUR', 'credit-wrong-currency');
            self::fail('Referral credit must not perform an implicit currency conversion.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('currency', $exception->errors());
        }

        $foreignOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        app(OrganizationContext::class)->set($organization);

        $this->expectException(HttpException::class);
        app(ApplyReferralCreditToObligation::class)->handle($foreignClient, $obligation->getKey(), '1.00', 'USD', 'credit-foreign-client');
    }

    public function test_cross_currency_redemption_debits_base_credit_and_records_target_settlement(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'cross-currency-credit', '100.00');
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD', 'RUB'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
            'rates' => [
                ['source_currency' => 'USD', 'target_currency' => 'RUB', 'rate' => '85'],
                ['source_currency' => 'RUB', 'target_currency' => 'USD', 'rate' => '0.01'],
            ],
        ]);
        $obligation = $this->bookingObligation($organization, $client, 'cross-currency-obligation', 850000, 'RUB');

        $entry = app(ApplyReferralCreditToObligation::class)->handle(
            client: $client,
            obligationId: $obligation->getKey(),
            amount: '5000.00',
            currency: 'RUB',
            idempotencyKey: 'cross-currency-redemption',
        );

        self::assertSame(5882, $entry->amount_minor);
        self::assertSame('USD', $entry->currency->value);
        self::assertSame(500000, $entry->settlement_amount_minor);
        self::assertSame('RUB', $entry->settlement_currency->value);
        self::assertSame(4118, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
        self::assertSame('USD', $entry->conversion_snapshot['settlement']['source_currency']);
        self::assertSame('RUB', $entry->conversion_snapshot['settlement']['target_currency']);
        self::assertSame('5882', $entry->conversion_snapshot['settlement']['source_amount_minor']);
        self::assertSame('500000', $entry->conversion_snapshot['settlement']['target_amount_minor']);
        self::assertSame(350000, app(ReconcileFinancialObligation::class)
            ->handle($organization->getKey(), $obligation->getKey())
            ->outstanding
            ->minorUnits());
    }

    public function test_cross_currency_restore_returns_historical_base_debit_after_rate_change(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'cross-currency-restore', '100.00');
        $this->configureMultiCurrency($organization, $admin, '85');
        $obligation = $this->bookingObligation($organization, $client, 'cross-currency-restore-obligation', 850000, 'RUB');
        $original = app(ApplyReferralCreditToObligation::class)->handle(
            client: $client,
            obligationId: $obligation->getKey(),
            amount: '5000.00',
            currency: 'RUB',
            idempotencyKey: 'cross-currency-restore-apply',
        );

        $this->configureMultiCurrency($organization, $admin, '100');
        $correction = app(RestoreReferralCredit::class)->handle(
            actor: $admin,
            entry: $original,
            reason: 'Исторический возврат бонусов.',
            idempotencyKey: 'cross-currency-restore-request',
        );

        self::assertSame(5882, ReferralRewardLedgerEntry::query()
            ->where('entry_type', ReferralRewardLedgerEntryType::Restored->value)
            ->sole()
            ->amount_minor);
        self::assertSame('USD', ReferralRewardLedgerEntry::query()
            ->where('entry_type', ReferralRewardLedgerEntryType::Restored->value)
            ->sole()
            ->currency->value);
        self::assertSame($admin->getKey(), $correction->actor_user_id);
        self::assertSame(10000, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
    }

    public function test_cross_currency_redemption_fails_closed_when_the_configured_rate_is_missing(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'missing-rate', '100.00');
        $this->configureMultiCurrency($organization, $admin, '85');
        DB::table('organization_exchange_rates')
            ->where('organization_id', $organization->getKey())
            ->where('source_currency', 'USD')
            ->where('target_currency', 'RUB')
            ->delete();
        $obligation = $this->bookingObligation($organization, $client, 'missing-rate-obligation', 850000, 'RUB');

        try {
            app(ApplyReferralCreditToObligation::class)->handle(
                client: $client,
                obligationId: $obligation->getKey(),
                amount: '5000.00',
                currency: 'RUB',
                idempotencyKey: 'missing-rate-redemption',
            );
            self::fail('A missing configured FX rate must reject redemption.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('currency', $exception->errors());
        }

        self::assertSame(0, FinancialLedgerEntry::query()
            ->where('entry_type', FinancialLedgerEntryType::ReferralCredit->value)
            ->count());
        self::assertSame(10000, app(ReferralRewardBalanceProjection::class)
            ->serviceCredit($client)
            ->available()
            ->minorUnits());
    }

    public function test_same_idempotency_key_rejects_a_different_redemption_request(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'credit-key', '10.00');
        $obligation = $this->bookingObligation($organization, $client, 'credit-key-obligation', 2000);
        app(ApplyReferralCreditToObligation::class)->handle($client, $obligation->getKey(), '4.00', 'USD', 'credit-reused-key');

        $this->expectException(ValidationException::class);
        app(ApplyReferralCreditToObligation::class)->handle($client, $obligation->getKey(), '5.00', 'USD', 'credit-reused-key');
    }

    public function test_restore_is_append_only_and_returns_credit_after_finance_correction(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'credit-restore', '10.00');
        $obligation = $this->bookingObligation($organization, $client, 'credit-restore-obligation', 2000);
        $original = app(ApplyReferralCreditToObligation::class)->handle($client, $obligation->getKey(), '4.00', 'USD', 'credit-restore-apply');
        $originalAmount = $original->amount_minor;

        self::assertSame(600, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
        $correction = app(RestoreReferralCredit::class)->handle($admin, $original, 'Отмена внутренней оплаты бонусом', 'credit-restore-request');
        $retry = app(RestoreReferralCredit::class)->handle($admin, $original, 'Отмена внутренней оплаты бонусом', 'credit-restore-request');

        self::assertSame($correction->getKey(), $retry->getKey());
        self::assertSame($originalAmount, $original->refresh()->amount_minor);
        self::assertSame(1000, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
        self::assertDatabaseHas('referral_reward_ledger_entries', [
            'entry_type' => ReferralRewardLedgerEntryType::Restored->value,
            'reverses_entry_id' => ReferralRewardLedgerEntry::query()
                ->where('entry_type', ReferralRewardLedgerEntryType::Redeemed->value)
                ->value('id'),
            'amount_minor' => 400,
        ]);
        self::assertSame(1, FinancialLedgerEntry::query()->where('corrects_ledger_entry_id', $original->getKey())->count());

        $this->expectException(ValidationException::class);
        app(RestoreReferralCredit::class)->handle($admin, $original, 'Вторая попытка', 'credit-restore-second-request');
    }

    public function test_promoting_an_ordinary_client_does_not_make_historical_credit_withdrawable(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'credit-role-change', '10.00');

        app(ActivateReferralPartner::class)->handle($client, 'crm', $admin);

        self::assertSame(1000, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
        self::assertSame(0, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::PartnerCash)
            ->available()
            ->minorUnits());

        $this->expectException(ValidationException::class);
        app(RequestReferralPayout::class)->handle($client, '1.00', 'USD', 'historical-credit-payout');
    }

    public function test_partner_payout_does_not_reduce_historical_service_credit(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $firstReferred = Client::factory()->forOrganization($organization)->create();
        $this->earnServiceCredit($organization, $admin, $client, 'credit-before-payout', '10.00', $firstReferred);
        app(ActivateReferralPartner::class)->handle($client, 'crm', $admin);
        $this->earnServiceCredit($organization, $admin, $client, 'cash-before-payout', '10.00');

        app(RequestReferralPayout::class)->handle($client, '10.00', 'USD', 'partner-payout-after-promotion');

        self::assertSame(1000, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
        self::assertSame(0, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::PartnerCash)
            ->available()
            ->minorUnits());
    }

    public function test_ordinary_client_overview_keeps_a_stable_personal_link_without_partner_controls(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->earnServiceCredit($organization, $admin, $client, 'ordinary-overview', '10.00');
        $overview = app(GetReferralPartnerOverview::class)->handle($client);

        self::assertFalse($overview['isPartner']);
        self::assertStringContainsString('start=ref_', $overview['link']);
        self::assertSame([], $overview['links']);
        self::assertNull($overview['rewards']['requestUrl']);
        self::assertSame(1000, $overview['rewards']['balances'][0]['availableMinor']);
        self::assertArrayNotHasKey('activationUrl', $overview);
        self::assertArrayNotHasKey('createLinkUrl', $overview);
    }

    public function test_ordinary_reward_qualification_stays_at_one_direct_referral_level(): void
    {
        [$organization, $admin, $firstClient] = $this->fixture();
        $secondClient = Client::factory()->forOrganization($organization)->create();
        $thirdClient = Client::factory()->forOrganization($organization)->create();
        $this->relationship($organization, $firstClient, $secondClient);
        $this->relationship($organization, $secondClient, $thirdClient);
        $this->configureReward($organization, $admin, '10.00', 'USD', 'every_settled_payment');
        [$obligation, $ledgerEntry] = $this->financeFixture($organization, $thirdClient, 'one-level-only', 10000, 'USD');
        app(RecordFinancialSettlementEvent::class)->handle($obligation, $ledgerEntry, $ledgerEntry->occurred_at);
        $event = IntegrationEvent::query()->where('aggregate_id', $obligation->getKey())->latest('id')->firstOrFail();
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());

        self::assertSame(1, ReferralRewardLedgerEntry::query()->count());
        self::assertSame($secondClient->getKey(), ReferralRewardLedgerEntry::query()->sole()->beneficiary_client_id);
        self::assertSame(ReferralRewardCategory::ServiceCredit, ReferralRewardLedgerEntry::query()->sole()->reward_category);
    }

    public function test_new_reward_after_crm_partner_assignment_is_cash_category(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $firstReferred = Client::factory()->forOrganization($organization)->create();
        $this->earnServiceCredit($organization, $admin, $client, 'credit-before-role', '10.00', $firstReferred);
        app(ActivateReferralPartner::class)->handle($client, 'crm', $admin);
        $secondReferred = Client::factory()->forOrganization($organization)->create();
        $this->relationship($organization, $client, $secondReferred);
        $this->configureReward($organization, $admin, '10.00', 'USD', 'every_settled_payment');
        [$obligation, $ledgerEntry] = $this->financeFixture($organization, $secondReferred, 'credit-after-role', 10000, 'USD');
        app(RecordFinancialSettlementEvent::class)->handle($obligation, $ledgerEntry, $ledgerEntry->occurred_at);
        $event = IntegrationEvent::query()->where('aggregate_id', $obligation->getKey())->latest('id')->firstOrFail();
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());

        self::assertSame([
            ReferralRewardCategory::ServiceCredit->value,
            ReferralRewardCategory::PartnerCash->value,
        ], ReferralRewardLedgerEntry::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (ReferralRewardLedgerEntry $entry): string => $entry->reward_category->value)
            ->all());

        $overview = app(GetReferralPartnerOverview::class)->handle($client);
        self::assertSame(1000, $overview['rewards']['balances'][0]['availableMinor']);
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
            'rates' => [],
        ]);

        return [$organization, $admin, $client];
    }

    private function configureMultiCurrency(Organization $organization, User $admin, string $usdToRub): void
    {
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD', 'RUB'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
            'rates' => [
                ['source_currency' => 'USD', 'target_currency' => 'RUB', 'rate' => $usdToRub],
                ['source_currency' => 'RUB', 'target_currency' => 'USD', 'rate' => '0.01'],
            ],
        ]);
    }

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    private function earnServiceCredit(
        Organization $organization,
        User $admin,
        Client $client,
        string $suffix,
        string $amount,
        ?Client $referred = null,
    ): ReferralRewardLedgerEntry {
        $referred ??= Client::factory()->forOrganization($organization)->create();
        $this->relationship($organization, $client, $referred);
        $this->configureReward($organization, $admin, $amount, 'USD', 'every_settled_payment');
        [$obligation, $ledgerEntry] = $this->financeFixture($organization, $referred, $suffix, 10000, 'USD');
        app(RecordFinancialSettlementEvent::class)->handle($obligation, $ledgerEntry, $ledgerEntry->occurred_at);
        $event = IntegrationEvent::query()->where('aggregate_id', $obligation->getKey())->latest('id')->firstOrFail();
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());

        return ReferralRewardLedgerEntry::query()->where('referral_relationship_id', $this->latestRelationship($client, $referred)->getKey())->latest('id')->firstOrFail();
    }

    private function configureReward(Organization $organization, User $admin, string $amount, string $currency, string $rule): void
    {
        app(SaveReferralRewardProgram::class)->handle(
            actor: $admin,
            enabled: true,
            qualificationRule: $rule,
            formula: 'fixed_amount',
            fixedAmount: $amount,
            fixedCurrency: $currency,
            percentage: null,
            effectiveAt: CarbonImmutable::now()->subMinute(),
        );
    }

    private function relationship(Organization $organization, Client $referrer, Client $referred): ReferralRelationship
    {
        $relationship = new ReferralRelationship;
        $relationship->forceFill([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $referrer->getKey(),
            'referred_client_id' => $referred->getKey(),
            'establishment_method' => 'manual_crm',
            'registered_at' => now(),
        ])->save();

        return $relationship;
    }

    private function latestRelationship(Client $referrer, Client $referred): ReferralRelationship
    {
        return ReferralRelationship::query()
            ->where('referrer_client_id', $referrer->getKey())
            ->where('referred_client_id', $referred->getKey())
            ->latest('id')
            ->firstOrFail();
    }

    private function bookingObligation(Organization $organization, Client $client, string $suffix, int $amountMinor, string $currency = 'USD'): FinancialObligation
    {
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => $amountMinor,
            'price_currency' => $currency,
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();

        return $this->obligation(
            organization: $organization,
            client: $client,
            bookingId: $booking->getKey(),
            serviceId: $service->getKey(),
            purchaseId: null,
            suffix: $suffix,
            amountMinor: $amountMinor,
            currency: $currency,
        );
    }

    private function purchaseObligation(Organization $organization, Client $client, string $suffix, int $amountMinor): FinancialObligation
    {
        $purchase = new Purchase;
        $purchase->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => 'pending_payment',
            'total_amount_minor' => $amountMinor,
            'currency' => 'USD',
            'purchase_snapshot' => ['name' => 'Тестовый товар'],
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        return $this->obligation(
            organization: $organization,
            client: $client,
            bookingId: null,
            serviceId: null,
            purchaseId: $purchase->getKey(),
            suffix: $suffix,
            amountMinor: $amountMinor,
        );
    }

    private function obligation(
        Organization $organization,
        Client $client,
        ?int $bookingId,
        ?int $serviceId,
        ?int $purchaseId,
        string $suffix,
        int $amountMinor,
        string $currency = 'USD',
    ): FinancialObligation {
        $baseSnapshot = app(CurrencyConfigurationService::class)->convert(
            $organization,
            Money::ofMinor($amountMinor, $currency),
            'USD',
        );
        $displaySnapshot = $baseSnapshot;
        $snapshot = [
            'source_amount_minor' => (string) $amountMinor,
            'source_currency' => $currency,
            'target_amount_minor' => $baseSnapshot->targetAmountMinor,
            'target_currency' => 'USD',
            'rate' => $baseSnapshot->rate,
            'rate_id' => $baseSnapshot->rateId,
            'rate_version' => $baseSnapshot->rateVersion,
            'effective_at' => $baseSnapshot->effectiveAt?->toIso8601String(),
            'rounding_mode' => $baseSnapshot->roundingMode->value,
            'source_scale' => $baseSnapshot->sourceScale,
            'target_scale' => $baseSnapshot->targetScale,
        ];
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => $bookingId,
            'service_id' => $serviceId,
            'purchase_id' => $purchaseId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'base_amount_minor' => (int) $baseSnapshot->targetAmountMinor,
            'base_currency' => 'USD',
            'display_amount_minor' => (int) $displaySnapshot->targetAmountMinor,
            'display_currency' => 'USD',
            'payment_amount_minor' => $amountMinor,
            'payment_currency' => $currency,
            'settlement_amount_minor' => $amountMinor,
            'settlement_currency' => $currency,
            'price_snapshot' => ['amount_minor' => $amountMinor],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => 'referral-credit-'.$suffix.'-'.$client->getKey(),
        ]);
        $obligation->save();

        return $obligation->refresh();
    }

    /** @return array{0: FinancialObligation, 1: FinancialLedgerEntry} */
    private function financeFixture(Organization $organization, Client $client, string $suffix, int $amountMinor, string $currency): array
    {
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => $amountMinor,
            'price_currency' => $currency,
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $obligation = $this->obligation($organization, $client, $booking->getKey(), $service->getKey(), null, $suffix, $amountMinor);
        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
            'organization_id' => $organization->getKey(),
            'obligation_id' => $obligation->getKey(),
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'payment_amount_minor' => $amountMinor,
            'payment_currency' => $currency,
            'base_amount_minor' => $amountMinor,
            'base_currency' => $currency,
            'display_amount_minor' => $amountMinor,
            'display_currency' => $currency,
            'settlement_amount_minor' => $amountMinor,
            'settlement_currency' => $currency,
            'payment_method' => 'cash',
            'occurred_at' => now(),
            'idempotency_key' => 'referral-credit-fixture-'.$suffix.'-'.$client->getKey(),
            'created_at' => now(),
        ]);
        $entry->save();

        return [$obligation, $entry];
    }
}
