<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Feedback\Application\RecordNpsSubmission;
use App\Modules\Finance\Application\RecordFinancialSettlementEvent;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Application\RegisterClientAcquisition;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ConsumeFinanceSettlementEvent;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use App\Modules\Referrals\Application\EstablishManualReferralRelationship;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use App\Modules\Referrals\Domain\Models\ReferralCommercialEvidence;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class MilestoneElevenAConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_acquisition_crash_retry_finalizes_original_referral_once(): void
    {
        $this->requirePostgres();
        [$organization, $referrer, $referred] = $this->referralFixture();
        $identity = app(EnsureReferralIdentity::class)->handle($referrer);
        $sessionId = 'm11a-pg-crash-retry';
        app(CapturePreAuthAttribution::class)->handle($sessionId, ['referral_code' => $identity->public_code]);
        app(RegisterClientAcquisition::class)->handle($organization, $referred, $sessionId);

        $freshClient = Client::query()->whereKey($referred->getKey())->firstOrFail();
        app(FinalizeClientAcquisition::class)->handle($freshClient, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($freshClient, $sessionId);

        self::assertSame(1, ReferralRelationship::query()->where('referred_client_id', $referred->getKey())->count());
        self::assertSame(1, DB::table('client_attributions')->where('client_id', $referred->getKey())->count());
    }

    public function test_postgresql_competing_referrer_claims_keep_one_original_attribution(): void
    {
        $this->requirePostgres();
        [$organization, $referrerA, $referred] = $this->referralFixture();
        $referrerB = Client::factory()->forOrganization($organization)->create();
        $identityA = app(EnsureReferralIdentity::class)->handle($referrerA);
        $identityB = app(EnsureReferralIdentity::class)->handle($referrerB);
        app(CapturePreAuthAttribution::class)->handle('m11a-pg-referrer-a', ['referral_code' => $identityA->public_code]);
        app(CapturePreAuthAttribution::class)->handle('m11a-pg-referrer-b', ['referral_code' => $identityB->public_code]);
        app(RegisterClientAcquisition::class)->handle($organization, $referred, 'm11a-pg-referrer-a');

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::claimInProcess($organization->getKey(), $referred->getKey(), 'm11a-pg-referrer-a'),
            static fn (): string => self::claimInProcess($organization->getKey(), $referred->getKey(), 'm11a-pg-referrer-b'),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, ReferralRelationship::query()->where('referred_client_id', $referred->getKey())->count());
        self::assertSame($referrerA->getKey(), ReferralRelationship::query()->where('referred_client_id', $referred->getKey())->value('referrer_client_id'));
        self::assertSame($identityA->public_code, DB::table('client_attributions')
            ->where('client_id', $referred->getKey())
            ->value('referral_code'));
        self::assertNull(DB::table('pre_auth_attributions')
            ->where('session_hash', hash('sha256', 'm11a-pg-referrer-b'))
            ->value('consumed_at'));
    }

    public function test_postgresql_concurrent_manual_referral_assignments_have_one_authorized_winner(): void
    {
        $this->requirePostgres();
        [$organization, $referrerA, $referred] = $this->referralFixture();
        $referrerB = Client::factory()->forOrganization($organization)->create();
        $actorA = User::factory()->forOrganization($organization)->create();
        $actorB = User::factory()->forOrganization($organization)->create();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::assignManualReferralInProcess(
                $organization->getKey(),
                $actorA->getKey(),
                $referrerA->getKey(),
                $referred->getKey(),
            ),
            static fn (): string => self::assignManualReferralInProcess(
                $organization->getKey(),
                $actorB->getKey(),
                $referrerB->getKey(),
                $referred->getKey(),
            ),
        ]);

        self::assertSame([], array_filter($results, static fn (string $result): bool => str_starts_with($result, 'error:')));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'created:'))));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'conflict')));
        self::assertSame(1, ReferralRelationship::query()
            ->where('organization_id', $organization->getKey())
            ->where('referred_client_id', $referred->getKey())
            ->count());
        self::assertContains(
            ReferralRelationship::query()
                ->where('organization_id', $organization->getKey())
                ->where('referred_client_id', $referred->getKey())
                ->value('referrer_client_id'),
            [$referrerA->getKey(), $referrerB->getKey()],
        );
    }

    public function test_postgresql_same_settlement_event_replay_and_concurrent_consumers_create_one_evidence_row(): void
    {
        $this->requirePostgres();
        [$organization, $referrer, $referred] = $this->referralFixture();
        $identity = app(EnsureReferralIdentity::class)->handle($referrer);
        app(CapturePreAuthAttribution::class)->handle('m11a-pg-evidence', ['referral_code' => $identity->public_code]);
        app(RegisterClientAcquisition::class)->handle($organization, $referred, 'm11a-pg-evidence');
        app(FinalizeClientAcquisition::class)->handle($referred, 'm11a-pg-evidence');
        [$obligation, $entry] = $this->financeFixture($organization, $referred);
        app(RecordFinancialSettlementEvent::class)->handle($obligation, $entry, $entry->occurred_at);
        app(RecordFinancialSettlementEvent::class)->handle($obligation, $entry, $entry->occurred_at);
        $event = IntegrationEvent::query()->where('idempotency_key', 'finance.obligation.settled:'.$organization->getKey().':'.$obligation->getKey())->firstOrFail();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::consumeInProcess($event->getKey()),
            static fn (): string => self::consumeInProcess($event->getKey()),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, ReferralCommercialEvidence::query()->where('integration_event_id', $event->getKey())->count());
        self::assertTrue(collect($results)->contains(static fn (string $result): bool => str_starts_with($result, 'evidence:')));
    }

    public function test_postgresql_finance_evidence_consumer_failure_leaves_payment_and_retryable_event(): void
    {
        $this->requirePostgres();
        [$organization, , $referred] = $this->referralFixture();
        [$obligation, $entry] = $this->financeFixture($organization, $referred);
        app(RecordFinancialSettlementEvent::class)->handle($obligation, $entry, $entry->occurred_at);
        $event = IntegrationEvent::query()->where('aggregate_id', $obligation->getKey())->firstOrFail();
        $audit = Mockery::mock(RecordAuditEvent::class);
        $audit->shouldReceive('handle')->andThrow(new RuntimeException('referral consumer failure'));
        $this->app->instance(RecordAuditEvent::class, $audit);

        try {
            app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
            self::fail('The simulated referral failure must be surfaced for retry.');
        } catch (RuntimeException $exception) {
            self::assertSame('referral consumer failure', $exception->getMessage());
        }

        self::assertDatabaseHas('financial_ledger_entries', ['id' => $entry->getKey()]);
        self::assertSame('retryable', IntegrationEvent::query()->whereKey($event->getKey())->value('status'));
        self::assertDatabaseCount('referral_commercial_evidence', 0);
    }

    public function test_postgresql_stale_worker_is_fenced_after_reclaim_and_finish(): void
    {
        $this->requirePostgres();
        [$organization, , $referred] = $this->referralFixture();
        [$obligation, $entry] = $this->financeFixture($organization, $referred, 'stale-worker');
        app(RecordFinancialSettlementEvent::class)->handle($obligation, $entry, $entry->occurred_at);
        $event = IntegrationEvent::query()->where('aggregate_id', $obligation->getKey())->firstOrFail();

        $claimResults = Concurrency::driver('process')->run([
            static fn (): string => self::claimEventInProcess($event->getKey()),
        ]);
        self::assertCount(1, $claimResults);
        self::assertStringStartsWith('claimed:', $claimResults[0]);
        $tokenA = substr($claimResults[0], strlen('claimed:'));
        IntegrationEvent::query()->whereKey($event->getKey())->update([
            'processing_started_at' => now()->subSeconds((int) config('referrals.events.stale_after_seconds', 300) + 1),
        ]);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::finishReclaimedEventInProcess($event->getKey()),
            static fn (): string => self::resumeStaleWorkerInProcess(
                $event->getKey(),
                $organization->getKey(),
                $tokenA,
            ),
        ]);

        self::assertContains('processed:'.$event->getKey(), $results);
        self::assertContains('stale-fenced', $results);
        self::assertSame(IntegrationEventStatus::Processed->value, IntegrationEvent::query()->whereKey($event->getKey())->value('status'));
        self::assertNull(IntegrationEvent::query()->whereKey($event->getKey())->value('processing_token'));
        self::assertSame(1, ReferralCommercialEvidence::query()
            ->where('integration_event_id', $event->getKey())
            ->count());
    }

    public function test_postgresql_same_nps_key_race_creates_one_submission(): void
    {
        $this->requirePostgres();
        [$organization, , $client] = $this->referralFixture();
        $results = Concurrency::driver('process')->run([
            static fn (): string => self::submitNpsInProcess($organization->getKey(), $client->getKey()),
            static fn (): string => self::submitNpsInProcess($organization->getKey(), $client->getKey()),
        ]);

        self::assertNotContains('error', $results);
        self::assertSame(1, DB::table('feedback_submissions')
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('idempotency_key', 'm11a-nps-race')
            ->count());
        self::assertSame(1, count(array_unique($results)));
    }

    /** @return array{Organization, Client, Client} */
    private function referralFixture(): array
    {
        $organization = Organization::factory()->create();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $referred = Client::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $referrer, $referred];
    }

    private static function claimInProcess(int $organizationId, int $clientId, string $sessionId): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            app(FinalizeClientAcquisition::class)->handle(
                Client::query()->where('organization_id', $organizationId)->findOrFail($clientId),
                $sessionId,
            );

            return 'claimed';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function assignManualReferralInProcess(
        int $organizationId,
        int $actorId,
        int $referrerClientId,
        int $referredClientId,
    ): string {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            config()->set('tenancy.default_organization_id', $organizationId);
            app(OrganizationContext::class)->set($organization);
            $relationship = app(EstablishManualReferralRelationship::class)->handle(
                actor: User::query()->findOrFail($actorId),
                referrerClientId: $referrerClientId,
                referredClientId: $referredClientId,
            );

            return 'created:'.$relationship->getKey();
        } catch (ValidationException) {
            return 'conflict';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function claimEventInProcess(int $eventId): string
    {
        try {
            $consumer = app(ConsumeFinanceSettlementEvent::class);
            $claim = self::invokePrivate($consumer, 'claim', [$eventId]);

            return is_array($claim) && isset($claim[1])
                ? 'claimed:'.$claim[1]
                : 'not-claimed';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function finishReclaimedEventInProcess(int $eventId): string
    {
        try {
            $evidence = app(ConsumeFinanceSettlementEvent::class)->handle($eventId);

            return $evidence instanceof ReferralCommercialEvidence
                ? 'processed:'.$eventId
                : 'not-processed';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function resumeStaleWorkerInProcess(int $eventId, int $organizationId, string $token): string
    {
        $deadline = microtime(true) + 10;
        do {
            $status = IntegrationEvent::query()->whereKey($eventId)->value('status');
            if ($status === IntegrationEventStatus::Processed->value) {
                break;
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        if ($status !== IntegrationEventStatus::Processed->value) {
            return 'error:worker-b-did-not-finish';
        }

        $consumer = app(ConsumeFinanceSettlementEvent::class);
        try {
            self::invokePrivate($consumer, 'validateAndRecord', [$eventId, $organizationId, $token]);
        } catch (\Throwable) {
        }
        self::invokePrivate($consumer, 'markProcessed', [$eventId, $organizationId, $token]);
        self::invokePrivate($consumer, 'markFailed', [$eventId, $organizationId, $token]);
        self::invokePrivate($consumer, 'markRetryable', [$eventId, $organizationId, $token]);

        $event = IntegrationEvent::query()->whereKey($eventId)->firstOrFail();

        return $event->status === IntegrationEventStatus::Processed ? 'stale-fenced' : 'error:stale-mutated-state';
    }

    private static function invokePrivate(object $object, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }

    private static function consumeInProcess(int $eventId): string
    {
        try {
            $evidence = app(ConsumeFinanceSettlementEvent::class)->handle($eventId);

            return 'evidence:'.$evidence?->getKey();
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function submitNpsInProcess(int $organizationId, int $clientId): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $submission = app(RecordNpsSubmission::class)->handle(
                client: Client::query()->where('organization_id', $organizationId)->findOrFail($clientId),
                score: 9,
                internalFeedback: null,
                idempotencyKey: 'm11a-nps-race',
            );

            return 'submission:'.$submission->getKey();
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    /** @return array{FinancialObligation, FinancialLedgerEntry} */
    private function financeFixture(Organization $organization, Client $client): array
    {
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        $booking = Booking::factory()->forClient($client)->forService($service)->create();
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
            'creation_key' => 'm11a-concurrency-'.$client->getKey().'-'.uniqid(),
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
            'idempotency_key' => 'm11a-concurrency-entry-'.uniqid(),
            'created_at' => now(),
        ]);
        $entry->save();

        return [$obligation, $entry];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('M11A concurrency tests require PostgreSQL row locks and unique indexes.');
        }
    }
}
