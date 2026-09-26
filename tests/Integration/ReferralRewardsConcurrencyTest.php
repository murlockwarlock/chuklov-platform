<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\RecordFinancialSettlementEvent;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Application\ApplyReferralCreditToObligation;
use App\Modules\Referrals\Application\ConsumeFinanceSettlementEvent;
use App\Modules\Referrals\Application\CreditManualReferralBonus;
use App\Modules\Referrals\Application\ReferralRewardBalanceProjection;
use App\Modules\Referrals\Application\RequestReferralPayout;
use App\Modules\Referrals\Application\ReverseReferralReward;
use App\Modules\Referrals\Application\SaveReferralRewardProgram;
use App\Modules\Referrals\Domain\Enums\ReferralRewardCategory;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ReferralRewardsConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_concurrent_settlement_consumers_create_one_reward_and_retry_stays_idempotent(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($admin, '10.00', 'USD');
        [$obligation, $entry] = $this->financeFixture($organization, $referred, 'consumer-race');
        app(RecordFinancialSettlementEvent::class)->handle($obligation, $entry, $entry->occurred_at);
        $event = IntegrationEvent::query()->where('aggregate_id', $obligation->getKey())->firstOrFail();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::consumeInProcess($event->getKey()),
            static fn (): string => self::consumeInProcess($event->getKey()),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, ReferralRewardLedgerEntry::query()->count());
        self::assertSame(1, DB::table('referral_commercial_evidence')->where('integration_event_id', $event->getKey())->count());
        self::assertSame(1, ReferralRewardLedgerEntry::query()->where('entry_type', 'earned')->count());
    }

    public function test_postgresql_concurrent_first_settled_payments_create_one_reward(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($admin, '10.00', 'USD');
        $firstEvent = $this->settledEvent($organization, $referred, 'first-payment-race-one');
        $secondEvent = $this->settledEvent($organization, $referred, 'first-payment-race-two');

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::consumeInProcess($firstEvent->getKey()),
            static fn (): string => self::consumeInProcess($secondEvent->getKey()),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, ReferralRewardLedgerEntry::query()->where('entry_type', 'earned')->count());
    }

    public function test_postgresql_concurrent_manual_bonus_replays_one_entry(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer] = $this->fixture();
        $profile = ReferralPartnerProfile::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $referrer->getKey())
            ->firstOrFail();
        $secondAdmin = User::factory()->forOrganization($organization)->create();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::manualBonusInProcess($organization->getKey(), $admin->getKey(), $profile->getKey()),
            static fn (): string => self::manualBonusInProcess($organization->getKey(), $secondAdmin->getKey(), $profile->getKey()),
        ]);

        self::assertNotContains('error', $results);
        self::assertCount(2, array_filter($results, static fn (string $result): bool => str_starts_with($result, 'bonus:')));
        self::assertSame(1, count(array_unique($results)));
        self::assertSame(1, ReferralRewardLedgerEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where('entry_type', 'manual_credit')
            ->count());
    }

    public function test_postgresql_concurrent_payout_requests_cannot_reserve_more_than_available(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($admin, '10.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'payout-race');
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::requestPayoutInProcess($organization->getKey(), $referrer->getKey(), '6.00', 'race-one'),
            static fn (): string => self::requestPayoutInProcess($organization->getKey(), $referrer->getKey(), '6.00', 'race-two'),
        ]);

        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'request:'))));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'validation')));
        self::assertSame(1, ReferralPayoutRequest::query()->count());
        self::assertSame(600, ReferralPayoutRequest::query()->sole()->amount_minor);
        self::assertSame(400, app(ReferralRewardBalanceProjection::class)->forCurrency($referrer, CurrencyCode::USD)->available()->minorUnits());
    }

    public function test_postgresql_concurrent_reversals_create_one_append_only_reversal(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $referrer, $referred] = $this->fixture();
        $this->relationship($organization, $referrer, $referred);
        $this->configureFixed($admin, '10.00', 'USD');
        $event = $this->settledEvent($organization, $referred, 'reversal-race');
        app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
        $earned = ReferralRewardLedgerEntry::query()->where('entry_type', 'earned')->sole();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::reverseInProcess($organization->getKey(), $admin->getKey(), $earned->getKey(), 'manual correction'),
            static fn (): string => self::reverseInProcess($organization->getKey(), $admin->getKey(), $earned->getKey(), 'manual correction'),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, ReferralRewardLedgerEntry::query()->where('entry_type', 'reversed')->count());
        self::assertSame(2, ReferralRewardLedgerEntry::query()->count());
        $reversalResults = array_values(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'reversal:')));
        self::assertCount(2, $reversalResults);
        self::assertCount(1, array_unique($reversalResults));
    }

    public function test_postgresql_concurrent_service_credit_redemptions_cannot_double_spend(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $client, $referred] = $this->ordinaryFixture();
        $this->relationship($organization, $client, $referred);
        $this->configureFixed($admin, '10.00', 'USD');
        $earnedEvent = $this->settledEvent($organization, $referred, 'service-credit-race');
        app(ConsumeFinanceSettlementEvent::class)->handle($earnedEvent->getKey());
        $obligation = $this->unpaidObligation($organization, $client, 'service-credit-redemption-race');

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::redeemInProcess($organization->getKey(), $client->getKey(), $obligation->getKey(), '6.00', 'service-credit-race-one'),
            static fn (): string => self::redeemInProcess($organization->getKey(), $client->getKey(), $obligation->getKey(), '6.00', 'service-credit-race-two'),
        ]);

        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'applied:'))));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'validation')));
        self::assertSame(1, ReferralRewardLedgerEntry::query()->where('entry_type', 'redeemed')->count());
        self::assertSame(400, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
    }

    public function test_postgresql_concurrent_cross_currency_redemptions_cannot_double_spend(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $client, $referred] = $this->crossCurrencyOrdinaryFixture();
        $this->relationship($organization, $client, $referred);
        $this->configureFixed($admin, '100.00', 'USD');
        $earnedEvent = $this->settledEvent($organization, $referred, 'cross-service-credit-race');
        app(ConsumeFinanceSettlementEvent::class)->handle($earnedEvent->getKey());
        $obligation = $this->unpaidCrossCurrencyObligation($organization, $client, 'cross-service-credit-redemption-race');

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::redeemInProcess($organization->getKey(), $client->getKey(), $obligation->getKey(), '5000.00', 'cross-race-one', 'RUB'),
            static fn (): string => self::redeemInProcess($organization->getKey(), $client->getKey(), $obligation->getKey(), '5000.00', 'cross-race-two', 'RUB'),
        ]);

        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'applied:'))));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'validation')));
        self::assertSame(1, ReferralRewardLedgerEntry::query()->where('entry_type', 'redeemed')->count());
        self::assertSame(4118, app(ReferralRewardBalanceProjection::class)
            ->forCurrency($client, CurrencyCode::USD, ReferralRewardCategory::ServiceCredit)
            ->available()
            ->minorUnits());
        self::assertSame(500000, FinancialLedgerEntry::query()
            ->where('entry_type', 'referral_credit')
            ->sole()
            ->settlement_amount_minor);
    }

    private static function consumeInProcess(int $eventId): string
    {
        try {
            $evidence = app(ConsumeFinanceSettlementEvent::class)->handle($eventId);

            return $evidence === null
                ? 'no-reward'
                : 'reward:'.ReferralRewardLedgerEntry::query()->where('referral_commercial_evidence_id', $evidence->getKey())->value('id');
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function requestPayoutInProcess(int $organizationId, int $clientId, string $amount, string $key): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $request = app(RequestReferralPayout::class)->handle(
                Client::query()->where('organization_id', $organizationId)->findOrFail($clientId),
                $amount,
                'USD',
                $key,
            );

            return 'request:'.$request->getKey();
        } catch (ValidationException) {
            return 'validation';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function reverseInProcess(int $organizationId, int $adminId, int $entryId, string $reason): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $reversal = app(ReverseReferralReward::class)->handle(
                User::query()->findOrFail($adminId),
                $entryId,
                $reason,
            );

            return 'reversal:'.$reversal->getKey();
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function redeemInProcess(int $organizationId, int $clientId, int $obligationId, string $amount, string $key, string $currency = 'USD'): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $entry = app(ApplyReferralCreditToObligation::class)->handle(
                client: Client::query()->where('organization_id', $organizationId)->findOrFail($clientId),
                obligationId: $obligationId,
                amount: $amount,
                currency: $currency,
                idempotencyKey: $key,
            );

            return 'applied:'.$entry->getKey();
        } catch (ValidationException) {
            return 'validation';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function manualBonusInProcess(int $organizationId, int $adminId, int $profileId): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $entry = app(CreditManualReferralBonus::class)->handle(
                actor: User::query()->findOrFail($adminId),
                partner: ReferralPartnerProfile::query()
                    ->where('organization_id', $organizationId)
                    ->findOrFail($profileId),
                amount: '10.00',
                currency: 'USD',
                reason: 'Concurrent manual bonus',
                comment: null,
                idempotencyKey: 'concurrent-manual-bonus',
            );

            return 'bonus:'.$entry->getKey();
        } catch (ValidationException) {
            return 'validation';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    /** @return array{0: Organization, 1: User, 2: Client, 3: Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        app(OrganizationContext::class)->set($organization);
        app(ActivateReferralPartner::class)->handle($referrer, 'crm', $admin);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);

        return [$organization, $admin, $referrer, $referred];
    }

    /** @return array{0: Organization, 1: User, 2: Client, 3: Client} */
    private function ordinaryFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        app(OrganizationContext::class)->set($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);

        return [$organization, $admin, $client, $referred];
    }

    /** @return array{0: Organization, 1: User, 2: Client, 3: Client} */
    private function crossCurrencyOrdinaryFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        app(OrganizationContext::class)->set($organization);
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

        return [$organization, $admin, $client, $referred];
    }

    private function configureFixed(User $admin, string $amount, string $currency): void
    {
        app(SaveReferralRewardProgram::class)->handle(
            actor: $admin,
            enabled: true,
            qualificationRule: 'first_settled_payment',
            formula: 'fixed_amount',
            fixedAmount: $amount,
            fixedCurrency: $currency,
            percentage: null,
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

    private function settledEvent(Organization $organization, Client $client, string $suffix): IntegrationEvent
    {
        [$obligation, $entry] = $this->financeFixture($organization, $client, $suffix);
        app(RecordFinancialSettlementEvent::class)->handle($obligation, $entry, $entry->occurred_at);

        return IntegrationEvent::query()->where('aggregate_id', $obligation->getKey())->firstOrFail();
    }

    /** @return array{FinancialObligation, FinancialLedgerEntry} */
    private function financeFixture(Organization $organization, Client $client, string $suffix): array
    {
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'USD',
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
            'source_amount_minor' => '10000',
            'source_currency' => 'USD',
            'target_amount_minor' => '10000',
            'target_currency' => 'USD',
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
            'amount_minor' => 10000,
            'currency' => 'USD',
            'base_amount_minor' => 10000,
            'base_currency' => 'USD',
            'display_amount_minor' => 10000,
            'display_currency' => 'USD',
            'payment_amount_minor' => 10000,
            'payment_currency' => 'USD',
            'settlement_amount_minor' => 10000,
            'settlement_currency' => 'USD',
            'price_snapshot' => ['amount_minor' => 10000],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => 'referral-rewards-race-'.$suffix.'-'.$client->getKey(),
        ]);
        $obligation->save();
        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
            'organization_id' => $organization->getKey(),
            'obligation_id' => $obligation->getKey(),
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => 10000,
            'currency' => 'USD',
            'payment_amount_minor' => 10000,
            'payment_currency' => 'USD',
            'base_amount_minor' => 10000,
            'base_currency' => 'USD',
            'display_amount_minor' => 10000,
            'display_currency' => 'USD',
            'settlement_amount_minor' => 10000,
            'settlement_currency' => 'USD',
            'payment_method' => 'cash',
            'conversion_snapshot' => null,
            'occurred_at' => now(),
            'idempotency_key' => 'referral-rewards-race-entry-'.$suffix.'-'.$client->getKey(),
            'created_at' => now(),
        ]);
        $entry->save();

        return [$obligation, $entry];
    }

    private function unpaidObligation(Organization $organization, Client $client, string $suffix): FinancialObligation
    {
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 1000,
            'price_currency' => 'USD',
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $snapshot = [
            'source_amount_minor' => '1000',
            'source_currency' => 'USD',
            'target_amount_minor' => '1000',
            'target_currency' => 'USD',
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
            'amount_minor' => 1000,
            'currency' => 'USD',
            'base_amount_minor' => 1000,
            'base_currency' => 'USD',
            'display_amount_minor' => 1000,
            'display_currency' => 'USD',
            'payment_amount_minor' => 1000,
            'payment_currency' => 'USD',
            'settlement_amount_minor' => 1000,
            'settlement_currency' => 'USD',
            'price_snapshot' => ['amount_minor' => 1000],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => 'referral-rewards-unpaid-'.$suffix.'-'.$client->getKey(),
        ]);
        $obligation->save();

        return $obligation->refresh();
    }

    private function unpaidCrossCurrencyObligation(Organization $organization, Client $client, string $suffix): FinancialObligation
    {
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 850000,
            'price_currency' => 'RUB',
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $snapshot = app(CurrencyConfigurationService::class)->convert(
            $organization,
            Money::ofMinor(850000, 'RUB'),
            'USD',
        )->toArray();
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => $booking->getKey(),
            'service_id' => $service->getKey(),
            'amount_minor' => 850000,
            'currency' => 'RUB',
            'base_amount_minor' => (int) $snapshot['target_amount_minor'],
            'base_currency' => 'USD',
            'display_amount_minor' => (int) $snapshot['target_amount_minor'],
            'display_currency' => 'USD',
            'payment_amount_minor' => 850000,
            'payment_currency' => 'RUB',
            'settlement_amount_minor' => 850000,
            'settlement_currency' => 'RUB',
            'price_snapshot' => ['amount_minor' => 850000],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => 'referral-rewards-cross-unpaid-'.$suffix.'-'.$client->getKey(),
        ]);
        $obligation->save();

        return $obligation->refresh();
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Referral reward concurrency coverage requires PostgreSQL row locks.');
        }
    }
}
