<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Attribution\Application\AcceptManualAttribution;
use App\Modules\Finance\Application\RecordFinancialSettlementEvent;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ConsumeFinanceSettlementEvent;
use App\Modules\Referrals\Application\EstablishManualReferralRelationship;
use App\Modules\Referrals\Application\GetClientReferralOverview;
use App\Modules\Referrals\Application\GetReferralRewardProgram;
use App\Modules\Referrals\Application\ReferralRewardBalanceProjection;
use App\Modules\Referrals\Application\RequestReferralPayout;
use App\Modules\Referrals\Application\ReverseReferralReward;
use App\Modules\Referrals\Application\SaveReferralRewardProgram;
use App\Modules\Referrals\Application\TransitionReferralPayoutRequest;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequestEvent;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Referrals\Domain\Models\ReferralRewardProgramVersion;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ReferralRewardsPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_reward_schema_uses_integer_append_only_tenant_scoped_records(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($admin, '10.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'schema');
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
        $earned = ReferralRewardLedgerEntry::query()->sole();
        $payout = app(RequestReferralPayout::class)->handle($referrer, '2.00', 'USD', 'schema-payout');
        $payoutEvent = $payout->events()->sole();

        $columns = DB::select(
            "SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_name IN ('referral_reward_ledger_entries', 'referral_payout_requests') AND column_name = 'amount_minor' ORDER BY table_name",
        );

        self::assertCount(2, $columns);
        foreach ($columns as $column) {
            self::assertSame('bigint', $column->data_type);
        }

        $version = ReferralRewardProgramVersion::query()->sole();
        $this->assertQueryFails(static fn (): mixed => DB::table('referral_reward_program_versions')
            ->where('id', $version->getKey())
            ->update(['version' => 99]));
        $this->assertQueryFails(static fn (): mixed => DB::table('referral_reward_program_versions')
            ->where('id', $version->getKey())
            ->delete());
        $this->assertQueryFails(static fn (): mixed => DB::table('referral_reward_ledger_entries')
            ->where('id', $earned->getKey())
            ->update(['reason' => 'mutated']));
        $this->assertQueryFails(static fn (): mixed => DB::table('referral_reward_ledger_entries')
            ->where('id', $earned->getKey())
            ->delete());
        $this->assertQueryFails(static fn (): mixed => DB::table('referral_payout_request_events')
            ->where('id', $payoutEvent->getKey())
            ->update(['reason' => 'mutated']));
        $this->assertQueryFails(static fn (): mixed => DB::table('referral_payout_request_events')
            ->where('id', $payoutEvent->getKey())
            ->delete());

        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $this->assertQueryFails(static fn (): mixed => DB::table('referral_payout_requests')->insert([
            'organization_id' => $organization->getKey(),
            'beneficiary_client_id' => $otherClient->getKey(),
            'amount_minor' => 1,
            'currency' => 'USD',
            'status' => ReferralPayoutRequestStatus::Requested->value,
            'idempotency_key' => 'cross-tenant-payout',
            'request_hash' => hash('sha256', 'cross-tenant-payout'),
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_postgresql_program_versions_support_fixed_percentage_rounding_and_default_disabled_state(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $default = app(GetReferralRewardProgram::class)->handle();

        self::assertFalse($default['enabled']);
        self::assertNull($default['version']);

        $fixed = $this->configureFixed($admin, '15.00', 'USD');
        $fixedEvent = $this->settledEvent($organization, $referred, 'fixed');
        app(ConsumeFinanceSettlementEvent::class)->handle($fixedEvent->getKey());
        $fixedEntry = ReferralRewardLedgerEntry::query()->sole();

        self::assertSame($fixed->getKey(), $fixedEntry->reward_program_version_id);
        self::assertSame(1500, $fixedEntry->amount_minor);
        self::assertSame('USD', $fixedEntry->currency->value);

        $percentage = $this->configurePercentage($admin, '12.34', 'half_up');
        self::assertSame('12.34', app(GetReferralRewardProgram::class)->handle()['percentage']);
        $percentageEvent = $this->settledEvent($organization, $referred, 'percentage', 10000, 'USD');
        app(ConsumeFinanceSettlementEvent::class)->handle($percentageEvent->getKey());
        $latestEntry = ReferralRewardLedgerEntry::query()->orderByDesc('id')->firstOrFail();
        self::assertSame(1234, $latestEntry->amount_minor);
        self::assertSame($percentage->getKey(), $latestEntry->reward_program_version_id);

        $this->configurePercentage($admin, '50', 'down');
        $roundingEvent = $this->settledEvent($organization, $referred, 'rounding', 1, 'USD');
        app(ConsumeFinanceSettlementEvent::class)->handle($roundingEvent->getKey());

        self::assertSame(2, ReferralRewardLedgerEntry::query()->count());
        self::assertDatabaseHas('referral_reward_program_versions', [
            'organization_id' => $organization->getKey(),
            'enabled' => true,
            'percentage_basis_points' => 5000,
            'rounding_mode' => 'down',
        ]);
    }

    public function test_postgresql_first_and_every_settled_payment_rules_and_retries_are_idempotent(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($admin, '10.00', 'USD', 'first_settled_payment');
        $first = $this->settledEvent($organization, $referred, 'first');
        app(ConsumeFinanceSettlementEvent::class)->handle($first->getKey());
        app(ConsumeFinanceSettlementEvent::class)->handle($first->getKey());
        $secondBeforeEdit = $this->settledEvent($organization, $referred, 'second-before-edit');
        app(ConsumeFinanceSettlementEvent::class)->handle($secondBeforeEdit->getKey());

        self::assertSame(1, ReferralRewardLedgerEntry::query()->count());

        $this->configureFixed($admin, '20.00', 'USD', 'every_settled_payment');
        $second = $this->settledEvent($organization, $referred, 'second');
        app(ConsumeFinanceSettlementEvent::class)->handle($second->getKey());
        app(ConsumeFinanceSettlementEvent::class)->handle($second->getKey());

        self::assertSame(2, ReferralRewardLedgerEntry::query()->count());
        self::assertSame([1000, 2000], ReferralRewardLedgerEntry::query()->orderBy('id')->pluck('amount_minor')->all());
        self::assertSame(1, DB::table('referral_commercial_evidence')->where('integration_event_id', $second->getKey())->count());
    }

    public function test_postgresql_disabled_program_blocks_future_rewards_but_preserves_history(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($admin, '10.00', 'USD', 'every_settled_payment');
        $first = $this->settledEvent($organization, $referred, 'disabled-first');
        app(ConsumeFinanceSettlementEvent::class)->handle($first->getKey());
        $disabled = app(SaveReferralRewardProgram::class)->handle(
            actor: $admin,
            enabled: false,
            qualificationRule: null,
            formula: null,
            fixedAmount: null,
            fixedCurrency: null,
            percentage: null,
            effectiveAt: CarbonImmutable::now()->subMinute(),
        );
        $second = $this->settledEvent($organization, $referred, 'disabled-second');
        app(ConsumeFinanceSettlementEvent::class)->handle($second->getKey());

        self::assertFalse($disabled->enabled);
        self::assertSame(1, ReferralRewardLedgerEntry::query()->count());
        self::assertSame(1000, ReferralRewardLedgerEntry::query()->sole()->amount_minor);
    }

    public function test_postgresql_organic_source_detail_does_not_qualify_but_authoritative_manual_relationship_does(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->configureFixed($admin, '10.00', 'USD');
        app(AcceptManualAttribution::class)->handle($referred, 'friend', 'Мария');
        $organic = $this->settledEvent($organization, $referred, 'organic');
        app(ConsumeFinanceSettlementEvent::class)->handle($organic->getKey());

        self::assertSame(0, ReferralRewardLedgerEntry::query()->count());

        $manualReferred = Client::factory()->forOrganization($organization)->create();
        app(EstablishManualReferralRelationship::class)->handle($admin, $referrer->getKey(), $manualReferred->getKey());
        $manual = $this->settledEvent($organization, $manualReferred, 'manual');
        app(ConsumeFinanceSettlementEvent::class)->handle($manual->getKey());

        self::assertSame(1, ReferralRewardLedgerEntry::query()->count());
        self::assertSame($manualReferred->getKey(), ReferralRewardLedgerEntry::query()->sole()->referred_client_id);
    }

    public function test_postgresql_program_edits_snapshot_rule_versions_and_do_not_rewrite_old_rewards(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $firstVersion = $this->configureFixed($admin, '10.00', 'USD', 'every_settled_payment');
        $first = $this->settledEvent($organization, $referred, 'history-first');
        app(ConsumeFinanceSettlementEvent::class)->handle($first->getKey());
        $secondVersion = $this->configureFixed($admin, '25.00', 'EUR', 'every_settled_payment');
        $second = $this->settledEvent($organization, $referred, 'history-second', 10000, 'EUR');
        app(ConsumeFinanceSettlementEvent::class)->handle($second->getKey());

        self::assertSame([1000, 2500], ReferralRewardLedgerEntry::query()->orderBy('id')->pluck('amount_minor')->all());
        self::assertSame(['USD', 'EUR'], DB::table('referral_reward_ledger_entries')->orderBy('id')->pluck('currency')->all());
        self::assertSame([$firstVersion->getKey(), $secondVersion->getKey()], ReferralRewardLedgerEntry::query()->orderBy('id')->pluck('reward_program_version_id')->all());

        $overview = app(GetClientReferralOverview::class)->handle($referrer);
        self::assertSame(['EUR', 'USD'], array_column($overview['rewards']['balances'], 'currency'));
        self::assertSame([2500, 1000], array_column($overview['rewards']['balances'], 'availableMinor'));
    }

    public function test_postgresql_payout_reservation_release_and_manual_payment_are_exactly_once(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($admin, '10.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'payout-lifecycle');
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());

        $request = app(RequestReferralPayout::class)->handle($referrer, '4.00', 'USD', 'payout-request-one');
        $balance = app(ReferralRewardBalanceProjection::class)->forCurrency($referrer, CurrencyCode::USD);
        self::assertSame(600, $balance->available()->minorUnits());
        self::assertSame(400, $balance->pending->minorUnits());

        app(TransitionReferralPayoutRequest::class)->handle($request, ReferralPayoutRequestStatus::Rejected, $admin, 'reject-one', 'Проверка реквизитов');
        self::assertSame(1000, app(ReferralRewardBalanceProjection::class)->forCurrency($referrer, CurrencyCode::USD)->available()->minorUnits());

        $cancelled = app(RequestReferralPayout::class)->handle($referrer, '3.00', 'USD', 'payout-request-two');
        app(TransitionReferralPayoutRequest::class)->handle($cancelled, ReferralPayoutRequestStatus::Cancelled, $referrer, 'cancel-two');
        self::assertSame(1000, app(ReferralRewardBalanceProjection::class)->forCurrency($referrer, CurrencyCode::USD)->available()->minorUnits());

        $paid = app(RequestReferralPayout::class)->handle($referrer, '7.00', 'USD', 'payout-request-three');
        app(TransitionReferralPayoutRequest::class)->handle($paid, ReferralPayoutRequestStatus::Approved, $admin, 'approve-three');
        app(TransitionReferralPayoutRequest::class)->handle($paid, ReferralPayoutRequestStatus::Paid, $admin, 'paid-three', paymentNote: 'Оплачено вручную вне системы', paymentReference: 'cash-1');
        app(TransitionReferralPayoutRequest::class)->handle($paid, ReferralPayoutRequestStatus::Paid, $admin, 'paid-three-retry', paymentNote: 'ignored');

        $final = app(ReferralRewardBalanceProjection::class)->forCurrency($referrer, CurrencyCode::USD);
        self::assertSame(700, $final->paid->minorUnits());
        self::assertSame(300, $final->available()->minorUnits());
        self::assertSame(3, ReferralPayoutRequest::query()->count());
        self::assertSame(1, ReferralPayoutRequest::query()->where('status', ReferralPayoutRequestStatus::Paid)->count());
        self::assertSame(3, ReferralPayoutRequestEvent::query()->where('payout_request_id', $paid->getKey())->count());
        self::assertSame('cash-1', $paid->refresh()->payment_reference);
    }

    public function test_postgresql_payout_limits_reject_cross_currency_and_reversed_rewards(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($admin, '10.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'payout-limits');
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());

        try {
            app(RequestReferralPayout::class)->handle($referrer, '10.01', 'USD', 'too-much');
            self::fail('A payout above the available balance must be rejected.');
        } catch (ValidationException) {
        }

        try {
            app(RequestReferralPayout::class)->handle($referrer, '1.00', 'EUR', 'wrong-currency');
            self::fail('A payout cannot consume another currency balance.');
        } catch (ValidationException) {
        }

        $earned = ReferralRewardLedgerEntry::query()->sole();
        app(ReverseReferralReward::class)->handle($admin, $earned, 'Оплата отменена вручную');

        try {
            app(RequestReferralPayout::class)->handle($referrer, '1.00', 'USD', 'reversed-withdrawal');
            self::fail('A reversed reward must not be withdrawable.');
        } catch (ValidationException) {
        }

        self::assertSame(2, ReferralRewardLedgerEntry::query()->count());
        self::assertSame(0, ReferralPayoutRequest::query()->count());
    }

    public function test_postgresql_payout_access_is_tenant_scoped(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($admin, '10.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'tenant');
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
        $request = app(RequestReferralPayout::class)->handle($referrer, '1.00', 'USD', 'tenant-payout');

        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();

        $this->expectException(AuthorizationException::class);
        app(TransitionReferralPayoutRequest::class)->handle($request, ReferralPayoutRequestStatus::Approved, $otherAdmin, 'cross-tenant-approve');
    }

    /** @return array{0: Organization, 1: User, 2: Client, 3: Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        $this->configureCurrency($admin);

        return [$organization, $admin, $referrer, $referred];
    }

    private function configureCurrency(User $admin, string $rounding = 'half_up'): void
    {
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD', 'EUR'],
            'force_single_currency' => false,
            'rounding_mode' => $rounding,
            'rates' => [
                ['source_currency' => 'EUR', 'target_currency' => 'USD', 'rate' => '1'],
                ['source_currency' => 'USD', 'target_currency' => 'EUR', 'rate' => '1'],
            ],
        ]);
    }

    private function configureFixed(User $admin, string $amount, string $currency, string $rule = 'first_settled_payment'): ReferralRewardProgramVersion
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

    private function configurePercentage(User $admin, string $percentage, string $rounding): ReferralRewardProgramVersion
    {
        $this->configureCurrency($admin, $rounding);

        return app(SaveReferralRewardProgram::class)->handle(
            actor: $admin,
            enabled: true,
            qualificationRule: 'every_settled_payment',
            formula: 'percentage_of_settlement',
            fixedAmount: null,
            fixedCurrency: null,
            percentage: $percentage,
            effectiveAt: CarbonImmutable::now()->subMinute(),
        );
    }

    private function relationship(Organization $organization, Client $referrer, Client $referred): void
    {
        DB::table('referral_relationships')->insert([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $referrer->getKey(),
            'referred_client_id' => $referred->getKey(),
            'establishment_method' => 'automatic_referral_link',
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
    private function financeFixture(Organization $organization, Client $client, string $suffix, int $amountMinor, string $currency): array
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
        $scale = app(CurrencyCatalog::class)->scale($currency);
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
            'source_scale' => $scale,
            'target_scale' => $scale,
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
            'creation_key' => 'referral-rewards-pg-'.$suffix.'-'.$client->getKey(),
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
            'idempotency_key' => 'referral-rewards-pg-entry-'.$suffix.'-'.$client->getKey(),
            'created_at' => now(),
        ]);
        $entry->save();

        return [$obligation, $entry];
    }

    private function assertQueryFails(Closure $operation): void
    {
        try {
            $operation();
            self::fail('The PostgreSQL constraint or append-only trigger must reject this operation.');
        } catch (QueryException) {
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Referral reward PostgreSQL coverage requires PostgreSQL constraints and transactions.');
        }
    }
}
