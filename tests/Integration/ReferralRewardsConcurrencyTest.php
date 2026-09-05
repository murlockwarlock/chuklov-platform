<?php

namespace Tests\Integration;

use App\Models\User;
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
use App\Modules\Referrals\Application\ReferralRewardBalanceProjection;
use App\Modules\Referrals\Application\RequestReferralPayout;
use App\Modules\Referrals\Application\ReverseReferralReward;
use App\Modules\Referrals\Application\SaveReferralRewardProgram;
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
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'reversal:'))));
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

    /** @return array{0: Organization, 1: User, 2: Client, 3: Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);

        return [$organization, $admin, $referrer, $referred];
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

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Referral reward concurrency coverage requires PostgreSQL row locks.');
        }
    }
}
