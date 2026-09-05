<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Attribution\Application\AcceptManualAttribution;
use App\Modules\Finance\Application\RecordFinancialSettlementEvent;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ConsumeFinanceSettlementEvent;
use App\Modules\Referrals\Application\GetClientReferralOverview;
use App\Modules\Referrals\Application\ReferralRewardBalanceProjection;
use App\Modules\Referrals\Application\RequestReferralPayout;
use App\Modules\Referrals\Application\ReverseReferralReward;
use App\Modules\Referrals\Application\SaveReferralRewardProgram;
use App\Modules\Referrals\Application\TransitionReferralPayoutRequest;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgramVersion;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ReferralRewardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rewards_are_disabled_until_an_authoritative_program_is_enabled(): void
    {
        [$organization, , $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $event = $this->settledEvent($organization, $referred, 'disabled');

        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());

        self::assertDatabaseCount('referral_commercial_evidence', 1);
        self::assertDatabaseCount('referral_reward_ledger_entries', 0);
    }

    public function test_fixed_reward_uses_the_configured_amount_and_manual_relationship(): void
    {
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred, 'manual_crm');
        $this->configureFixed($organization, $admin, '15.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'fixed');

        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
        $entry = ReferralRewardLedgerEntry::query()->sole();

        self::assertSame(1500, $entry->amount_minor);
        self::assertSame('USD', $entry->currency->value);
        self::assertSame(ReferralRewardLedgerEntryType::Earned, $entry->entry_type);
        self::assertSame($referred->getKey(), $entry->referred_client_id);
        self::assertSame($referrer->getKey(), $entry->beneficiary_client_id);
    }

    public function test_percentage_reward_uses_settlement_minor_units_and_configured_rounding(): void
    {
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureCurrency($organization, $admin, 'half_up');
        app(SaveReferralRewardProgram::class)->handle(
            actor: $admin,
            enabled: true,
            qualificationRule: 'every_settled_payment',
            formula: 'percentage_of_settlement',
            fixedAmount: null,
            fixedCurrency: null,
            percentage: '50',
            effectiveAt: CarbonImmutable::now()->subMinute(),
        );
        $event = $this->settledEvent($organization, $referred, 'percentage', 1);

        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());

        self::assertSame(1, ReferralRewardLedgerEntry::query()->sole()->amount_minor);
        self::assertSame('USD', ReferralRewardLedgerEntry::query()->sole()->currency->value);
    }

    public function test_first_and_every_qualification_rules_are_distinct_and_retries_are_idempotent(): void
    {
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($organization, $admin, '10.00', 'USD', 'first_settled_payment');
        $first = $this->settledEvent($organization, $referred, 'first');
        app(ConsumeFinanceSettlementEvent::class)->handle($first->getKey());
        app(ConsumeFinanceSettlementEvent::class)->handle($first->getKey());
        $this->configureFixed($organization, $admin, '20.00', 'USD', 'every_settled_payment');
        $second = $this->settledEvent($organization, $referred, 'second');
        app(ConsumeFinanceSettlementEvent::class)->handle($second->getKey());

        self::assertSame(2, ReferralRewardLedgerEntry::query()->count());
        self::assertSame([1000, 2000], ReferralRewardLedgerEntry::query()->orderBy('id')->pluck('amount_minor')->all());
    }

    public function test_organic_source_detail_without_relationship_never_qualifies(): void
    {
        [$organization, $admin, , $referred] = $this->fixture();
        $this->configureFixed($organization, $admin, '10.00', 'USD');
        app(AcceptManualAttribution::class)->handle($referred, 'friend', 'Мария');
        $event = $this->settledEvent($organization, $referred, 'organic');

        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());

        self::assertDatabaseCount('referral_reward_ledger_entries', 0);
    }

    public function test_program_edit_creates_version_and_leaves_historical_reward_unchanged(): void
    {
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $firstVersion = $this->configureFixed($organization, $admin, '10.00', 'USD');
        $first = $this->settledEvent($organization, $referred, 'history-first');
        app(ConsumeFinanceSettlementEvent::class)->handle($first->getKey());
        $secondVersion = $this->configureFixed($organization, $admin, '25.00', 'USD', 'every_settled_payment');
        $second = $this->settledEvent($organization, $referred, 'history-second');
        app(ConsumeFinanceSettlementEvent::class)->handle($second->getKey());

        self::assertNotSame($firstVersion->getKey(), $secondVersion->getKey());
        self::assertSame([1000, 2500], ReferralRewardLedgerEntry::query()->orderBy('id')->pluck('amount_minor')->all());
        self::assertSame([$firstVersion->getKey(), $secondVersion->getKey()], ReferralRewardLedgerEntry::query()->orderBy('id')->pluck('reward_program_version_id')->all());
    }

    public function test_disabling_the_current_version_stops_future_qualification_without_changing_history(): void
    {
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($organization, $admin, '10.00', 'USD', 'every_settled_payment');
        $first = $this->settledEvent($organization, $referred, 'disable-first');
        app(ConsumeFinanceSettlementEvent::class)->handle($first->getKey());

        app(SaveReferralRewardProgram::class)->handle(
            actor: $admin,
            enabled: false,
            qualificationRule: null,
            formula: null,
            fixedAmount: null,
            fixedCurrency: null,
            percentage: null,
            effectiveAt: CarbonImmutable::now()->subMinute(),
        );
        $second = $this->settledEvent($organization, $referred, 'disable-second');
        app(ConsumeFinanceSettlementEvent::class)->handle($second->getKey());

        self::assertSame(1, ReferralRewardLedgerEntry::query()->count());
        self::assertSame(1000, ReferralRewardLedgerEntry::query()->sole()->amount_minor);
    }

    public function test_balances_remain_separate_when_rewards_use_different_currencies(): void
    {
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($organization, $admin, '10.00', 'USD', 'every_settled_payment');
        $usd = $this->settledEvent($organization, $referred, 'currency-usd', 10000, 'USD');
        app(ConsumeFinanceSettlementEvent::class)->handle($usd->getKey());
        $this->configureCurrency($organization, $admin, 'half_up', ['USD', 'EUR'], [
            ['source_currency' => 'EUR', 'target_currency' => 'USD', 'rate' => '1'],
        ]);
        $this->configureFixed($organization, $admin, '5.00', 'EUR', 'every_settled_payment');
        $eur = $this->settledEvent($organization, $referred, 'currency-eur', 10000, 'EUR');
        app(ConsumeFinanceSettlementEvent::class)->handle($eur->getKey());

        $overview = app(GetClientReferralOverview::class)->handle($referrer);

        self::assertCount(2, $overview['rewards']['balances']);
        self::assertSame(['EUR', 'USD'], array_column($overview['rewards']['balances'], 'currency'));
        self::assertSame([500, 1000], array_column($overview['rewards']['balances'], 'availableMinor'));
    }

    public function test_payout_reserves_releases_and_finalizes_the_derived_balance(): void
    {
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($organization, $admin, '10.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'payout');
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
        $request = app(RequestReferralPayout::class)->handle($referrer, '4.00', 'USD', 'payout-request-one');
        $balance = app(ReferralRewardBalanceProjection::class)->forCurrency($referrer, CurrencyCode::USD);

        self::assertSame(600, $balance->available()->minorUnits());
        self::assertSame(400, $balance->pending->minorUnits());
        app(TransitionReferralPayoutRequest::class)->handle($request, ReferralPayoutRequestStatus::Rejected, $admin, 'reject-one', 'Проверка реквизитов');
        self::assertSame(1000, $balance = app(ReferralRewardBalanceProjection::class)->forCurrency($referrer, CurrencyCode::USD)->available()->minorUnits());
        $cancelled = app(RequestReferralPayout::class)->handle($referrer, '3.00', 'USD', 'payout-request-two');
        app(TransitionReferralPayoutRequest::class)->handle($cancelled, ReferralPayoutRequestStatus::Cancelled, $referrer, 'cancel-two');
        $paid = app(RequestReferralPayout::class)->handle($referrer, '7.00', 'USD', 'payout-request-three');
        app(TransitionReferralPayoutRequest::class)->handle($paid, ReferralPayoutRequestStatus::Approved, $admin, 'approve-three');
        app(TransitionReferralPayoutRequest::class)->handle($paid, ReferralPayoutRequestStatus::Paid, $admin, 'paid-three', paymentNote: 'Оплачено вручную вне системы', paymentReference: 'cash-1');
        app(TransitionReferralPayoutRequest::class)->handle($paid, ReferralPayoutRequestStatus::Paid, $admin, 'paid-three-retry', paymentNote: 'ignored');

        $final = app(ReferralRewardBalanceProjection::class)->forCurrency($referrer, CurrencyCode::USD);
        self::assertSame(700, $final->paid->minorUnits());
        self::assertSame(300, $final->available()->minorUnits());
        self::assertSame(3, ReferralPayoutRequest::query()->count());
        self::assertSame(1, ReferralPayoutRequest::query()->where('status', ReferralPayoutRequestStatus::Paid)->count());
    }

    public function test_payout_above_balance_cross_currency_and_reversed_reward_are_rejected(): void
    {
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($organization, $admin, '10.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'limits');
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
        $entry = ReferralRewardLedgerEntry::query()->sole();

        $this->expectException(ValidationException::class);
        app(RequestReferralPayout::class)->handle($referrer, '10.01', 'USD', 'too-much');
    }

    public function test_reversal_prevents_withdrawal_and_is_append_only(): void
    {
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($organization, $admin, '10.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'reverse');
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
        $earned = ReferralRewardLedgerEntry::query()->sole();
        app(ReverseReferralReward::class)->handle($admin, $earned, 'Оплата отменена вручную');

        self::assertSame(2, ReferralRewardLedgerEntry::query()->count());
        self::assertSame(0, app(ReferralRewardBalanceProjection::class)->forCurrency($referrer, CurrencyCode::USD)->available()->minorUnits());
        $this->expectException(ValidationException::class);
        app(RequestReferralPayout::class)->handle($referrer, '1.00', 'USD', 'reversed-withdrawal');
    }

    public function test_portal_overview_exposes_separate_currency_balances_and_human_payout_statuses(): void
    {
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($organization, $admin, '10.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'overview');
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
        app(RequestReferralPayout::class)->handle($referrer, '2.00', 'USD', 'overview-payout');

        $overview = app(GetClientReferralOverview::class)->handle($referrer);

        self::assertSame(1, $overview['referredClientsCount']);
        self::assertNotEmpty($overview['rewards']['balances']);
        self::assertNotEmpty($overview['rewards']['payouts']);
        self::assertSame(1000, $overview['rewards']['balances'][0]['accruedMinor']);
        self::assertSame(800, $overview['rewards']['balances'][0]['availableMinor']);
        self::assertSame('Запрошена', $overview['rewards']['payouts'][0]['statusLabel']);
        self::assertArrayNotHasKey('status', $overview['rewards']['payouts'][0]);
    }

    /** @return array{Organization, User, Client, Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        $this->configureCurrency($organization, $admin);

        return [$organization, $admin, $referrer, $referred];
    }

    private function relationship(Organization $organization, Client $referrer, Client $referred, string $method = 'automatic_referral_link'): ReferralRelationship
    {
        $relationship = new ReferralRelationship;
        $relationship->forceFill([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $referrer->getKey(),
            'referred_client_id' => $referred->getKey(),
            'establishment_method' => $method,
            'registered_at' => now(),
        ]);
        $relationship->save();

        return $relationship;
    }

    /**
     * @param  list<string>  $allowedCurrencies
     * @param  list<array{source_currency: string, target_currency: string, rate: string}>  $rates
     */
    private function configureCurrency(
        Organization $organization,
        User $admin,
        string $rounding = 'half_up',
        array $allowedCurrencies = ['USD'],
        array $rates = [],
    ): void {
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => $allowedCurrencies,
            'force_single_currency' => count($allowedCurrencies) === 1,
            'rounding_mode' => $rounding,
            'rates' => $rates,
        ]);
    }

    private function configureFixed(Organization $organization, User $admin, string $amount, string $currency, string $rule = 'first_settled_payment'): ReferralRewardProgramVersion
    {
        return app(SaveReferralRewardProgram::class)->handle(
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

    private function settledEvent(Organization $organization, Client $client, string $suffix, int $amountMinor = 10000, string $currency = 'USD'): IntegrationEvent
    {
        [$obligation, $entry] = $this->financeFixture($organization, $client, $suffix, $amountMinor, $currency);
        app(RecordFinancialSettlementEvent::class)->handle($obligation, $entry, $entry->occurred_at);

        return IntegrationEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('aggregate_id', $obligation->getKey())
            ->firstOrFail();
    }

    /** @return array{FinancialObligation, FinancialLedgerEntry} */
    private function financeFixture(Organization $organization, Client $client, string $suffix, int $amountMinor, string $currency = 'USD'): array
    {
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => $amountMinor,
            'price_currency' => $currency,
        ]);
        if (! $service instanceof Service) {
            throw new \UnexpectedValueException('The service fixture was not created.');
        }
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $snapshot = [
            'source_amount_minor' => (string) $amountMinor,
            'source_currency' => $currency,
            'target_amount_minor' => (string) $amountMinor,
            'target_currency' => $currency,
            'rate' => '1',
            'rate_id' => null,
            'rate_version' => null,
            'effective_at' => null,
            'rounding_mode' => 'half_up',
            'source_scale' => 2,
            'target_scale' => 2,
        ];
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => $booking->getKey(),
            'service_id' => $service->getKey(),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'base_amount_minor' => $amountMinor,
            'base_currency' => $currency,
            'display_amount_minor' => $amountMinor,
            'display_currency' => $currency,
            'payment_amount_minor' => $amountMinor,
            'payment_currency' => $currency,
            'settlement_amount_minor' => $amountMinor,
            'settlement_currency' => $currency,
            'price_snapshot' => ['amount_minor' => $amountMinor],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => 'referral-reward-'.$suffix.'-'.$client->getKey(),
        ]);
        $obligation->save();
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
            'conversion_snapshot' => null,
            'occurred_at' => now(),
            'idempotency_key' => 'referral-reward-entry-'.$suffix.'-'.$client->getKey(),
            'created_at' => now(),
        ]);
        $entry->save();

        return [$obligation, $entry];
    }
}
