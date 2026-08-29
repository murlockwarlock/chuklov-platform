<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\B2B\Application\B2bProviderLeaseManager;
use App\Modules\B2B\Application\B2bProviderMutationGuard;
use App\Modules\B2B\Application\CancelB2bSalesCall;
use App\Modules\B2B\Application\SubmitB2bLead;
use App\Modules\B2B\Domain\Enums\B2bLeadSource;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationLease;
use App\Modules\B2B\Jobs\ProcessB2bProviderSyncEvent;
use App\Modules\Broadcasts\Application\SetClientB2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

final class B2bSalesCallPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_enforces_b2b_organization_links_unique_submission_keys_and_named_constraints(): void
    {
        $this->requirePostgres();
        [$organization, $client, $specialist] = $this->identityFixture();
        $lead = B2bLead::factory()->forClient($client)->create([
            'idempotency_key' => 'postgres-idempotency',
        ]);

        try {
            B2bLead::factory()->forClient($client)->create([
                'idempotency_key' => 'postgres-idempotency',
            ]);
            self::fail('PostgreSQL accepted a duplicate organization-scoped B2B idempotency key.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignClient = Client::factory()->forOrganization($otherOrganization)->create();
        try {
            B2bLead::query()->forceCreate([
                'organization_id' => $organization->getKey(),
                'client_id' => $foreignClient->getKey(),
                'b2b_specialist_answer' => 'yes',
                'source_channel' => 'portal',
                'idempotency_key' => 'forged-client',
                'request_hash' => hash('sha256', 'forged-client'),
                'status' => 'new',
                'event_version' => 1,
                'submitted_at' => now(),
            ]);
            self::fail('PostgreSQL accepted a B2B lead with a foreign organization/client pair.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('b2b_sales_calls')->insert([
                'organization_id' => $organization->getKey(),
                'lead_id' => $lead->getKey(),
                'client_id' => $foreignClient->getKey(),
                'specialist_id' => $specialist->getKey(),
                'status' => 'scheduled',
                'starts_at' => '2030-01-07 15:00:00+00',
                'ends_at' => '2030-01-07 16:00:00+00',
                'schedule_timezone' => 'UTC',
                'requested_timezone' => 'UTC',
                'meeting_mode' => 'manual',
                'provider_sync_status' => 'not_required',
                'event_version' => 1,
                'provider_sync_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::fail('PostgreSQL accepted a sales call whose lead and client did not match.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $relations = DB::select(
            "SELECT relname FROM pg_class WHERE relkind IN ('r', 'i', 'S') AND relname LIKE 'b2b_%'",
        );
        self::assertNotEmpty($relations);
        foreach ($relations as $relation) {
            self::assertLessThanOrEqual(63, strlen((string) $relation->relname));
        }
    }

    public function test_postgresql_specialist_lock_closes_the_booking_vs_b2b_sales_call_race(): void
    {
        $this->requirePostgres();
        $fixture = $this->schedulingFixture();
        $start = CarbonImmutable::create(2030, 1, 7, 15, 0, 0, 'UTC');

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::createBookingInProcess(
                $fixture['organization']->getKey(),
                $fixture['bookingClient']->getKey(),
                $fixture['specialist']->getKey(),
                $fixture['service']->getKey(),
                $start->toIso8601String(),
            ),
            static fn (): string => self::createSalesCallInProcess(
                $fixture['organization']->getKey(),
                $fixture['salesClient']->getKey(),
                $fixture['specialist']->getKey(),
                $start->toIso8601String(),
            ),
        ]);

        self::assertCount(2, $results);
        self::assertSame(
            1,
            count(array_filter($results, static fn (string $result): bool => in_array($result, ['booking', 'b2b'], true))),
        );
        self::assertSame(
            1,
            count(array_filter($results, static fn (string $result): bool => in_array($result, ['booking-conflict', 'b2b-conflict'], true))),
        );
        self::assertNotContains('booking-error', $results);
        self::assertNotContains('b2b-error', $results);
        self::assertNotContains('booking-validation-error', $results);
        self::assertNotContains('b2b-validation-error', $results);
        self::assertSame(
            1,
            Booking::query()->where('organization_id', $fixture['organization']->getKey())->count()
                + B2bLead::query()->where('organization_id', $fixture['organization']->getKey())->count(),
        );
        self::assertSame(
            B2bLead::query()->where('organization_id', $fixture['organization']->getKey())->count(),
            DB::table('b2b_sales_calls')->where('organization_id', $fixture['organization']->getKey())->count(),
        );
    }

    public function test_postgresql_active_provider_lease_blocks_a_concurrent_generation_writer(): void
    {
        $this->requirePostgres();
        $fixture = $this->schedulingFixture();
        $this->setOrganization($fixture['organization']);
        app(SetOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bZoomHostLicensed,
            true,
        );
        $start = CarbonImmutable::create(2030, 1, 7, 15, 0, 0, 'UTC');
        Queue::fake([
            ProcessB2bProviderSyncEvent::class,
        ]);
        $lead = app(SubmitB2bLead::class)->handle(
            actor: $fixture['salesClient'],
            client: $fixture['salesClient'],
            specialist: $fixture['specialist'],
            startsAt: $start,
            requestedTimezone: 'UTC',
            idempotencyKey: 'pg-provider-lease',
            source: B2bLeadSource::Portal,
            meetingMode: VideoMeetingMode::Automatic,
        );
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()
            ->where('organization_id', $fixture['organization']->getKey())
            ->where('aggregate_id', $call->getKey())
            ->sole();

        Queue::assertPushedOn(
            (string) config('b2b.queue'),
            ProcessB2bProviderSyncEvent::class,
            static fn (ProcessB2bProviderSyncEvent $job): bool => $job->integrationEventId === $event->getKey(),
        );
        self::assertSame('pending', $event->getRawOriginal('status'));

        $lease = app(B2bProviderLeaseManager::class)->claim($event->getKey());
        self::assertInstanceOf(ProviderOperationLease::class, $lease);
        $before = $call->fresh();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::cancelSalesCallInProcess(
                $fixture['organization']->getKey(),
                $fixture['admin']->getKey(),
                $call->getKey(),
                $before->event_version,
            ),
            static fn (): string => self::ownsProviderLeaseInProcess(
                $lease->organizationId,
                $lease->salesCallId,
                $lease->eventId,
                $lease->eventProcessingToken,
                $lease->leaseToken,
                $lease->eventVersion,
                $lease->providerSyncVersion,
                $lease->operation->value,
                $lease->providerDeadlineExpiresAt->toIso8601String(),
                $lease->leaseExpiresAt->toIso8601String(),
                $lease->requestSafetySeconds,
            ),
        ]);

        self::assertContains('blocked', $results);
        self::assertContains('owned', $results);
        $final = $call->fresh();
        self::assertSame($before->provider_correlation_key, $final->provider_correlation_key);
        self::assertSame($before->provider_sync_version, $final->provider_sync_version);
        self::assertSame($before->event_version, $final->event_version);
        self::assertSame($before->provider_lease_token, $final->provider_lease_token);
    }

    public function test_postgresql_m11d_constraints_preserve_historical_and_b2b_event_types(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $client = Client::factory()->forOrganization($organization)->create();
        $template = NotificationTemplate::factory()->forOrganization($organization)->create();
        $templateVersion = NotificationTemplateVersion::factory()->forTemplate($template)->create();

        $scenarioEventTypes = [
            'booking.completed',
            'onboarding.started',
            'finance.obligation.created',
            'survey.completed',
            'TEST_STAGNATION_DETECTED',
            'b2b.lead.submitted',
            'b2b.sales_call.ready',
        ];
        foreach ($scenarioEventTypes as $eventType) {
            $rule = ScenarioRule::factory()
                ->forOrganization($organization)
                ->usingTemplate($templateVersion)
                ->create([
                    'rule_key' => 'constraint-'.str_replace('.', '-', strtolower($eventType)),
                    'trigger_event' => $eventType,
                ]);
            $event = ScenarioEvent::factory()->forOrganization($organization)->create([
                'event_name' => $eventType,
                'idempotency_key' => 'constraint-event-'.$eventType,
            ]);
            ScenarioAction::factory()
                ->forEvent($event)
                ->forRule($rule)
                ->forTemplate($templateVersion)
                ->forClient($client)
                ->create(['trigger_event' => $eventType]);
        }

        foreach (['finance.obligation.settled', 'b2b.sales_call.provider_sync'] as $index => $eventType) {
            DB::table('integration_events')->insert([
                'organization_id' => $organization->getKey(),
                'event_type' => $eventType,
                'aggregate_type' => 'constraint-test',
                'aggregate_id' => $index + 1,
                'idempotency_key' => 'constraint-integration-'.$index,
                'payload' => '{}',
                'status' => 'pending',
                'attempt_count' => 0,
                'occurred_at' => now(),
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        self::assertSame(count($scenarioEventTypes), ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->count());
        self::assertSame(2, DB::table('integration_events')
            ->where('organization_id', $organization->getKey())
            ->count());

        try {
            DB::table('scenario_rules')->whereKey($rule->getKey())->update(['trigger_event' => 'not-supported']);
            self::fail('PostgreSQL accepted an unsupported Scenario rule trigger.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('scenario_actions')->where('scenario_rule_id', $rule->getKey())->update(['trigger_event' => 'not-supported']);
            self::fail('PostgreSQL accepted an unsupported Scenario action trigger.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('integration_events')
                ->where('idempotency_key', 'constraint-integration-0')
                ->update(['event_type' => 'not-supported']);
            self::fail('PostgreSQL accepted an unsupported integration event type.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_postgresql_m11d_migration_down_restores_exact_legacy_event_sets(): void
    {
        $this->requirePostgres();
        $migration = require base_path('database/migrations/2026_08_27_100001_extend_b2b_event_constraints.php');

        try {
            $migration->down();

            $scenarioRulesConstraint = $this->constraintDefinition('scenario_rules_m6_trigger_event_check');
            $scenarioActionsConstraint = $this->constraintDefinition('scenario_actions_m6_trigger_event_check');
            $integrationConstraint = $this->constraintDefinition('integration_events_type_check');
            foreach ([$scenarioRulesConstraint, $scenarioActionsConstraint] as $definition) {
                self::assertStringContainsString("'survey.completed'", $definition);
                self::assertStringContainsString("'TEST_STAGNATION_DETECTED'", $definition);
                self::assertStringNotContainsString("'b2b.lead.submitted'", $definition);
                self::assertStringNotContainsString("'b2b.sales_call.ready'", $definition);
            }
            self::assertStringContainsString("'finance.obligation.settled'", $integrationConstraint);
            self::assertStringNotContainsString("'b2b.sales_call.provider_sync'", $integrationConstraint);
        } finally {
            $migration->up();
        }
    }

    /** @return array{Organization, Client, Specialist} */
    private function identityFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);

        return [$organization, $client, $specialist];
    }

    /** @return array{organization: Organization, admin: User, bookingClient: Client, salesClient: Client, specialist: Specialist, service: Service} */
    private function schedulingFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $bookingClient = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $salesClient = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'formats' => ['office'],
        ]);
        $this->setOrganization($organization);
        foreach ([OrganizationFeature::ClientRecords, OrganizationFeature::ServiceCatalog] as $feature) {
            OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
                'feature_key' => $feature->value,
                'enabled' => true,
            ]);
        }
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            60,
        );
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);
        app(SetClientB2bSpecialistAnswer::class)->handle($salesClient, $salesClient, B2bSpecialistAnswer::Yes, 'portal');

        return compact('organization', 'admin', 'bookingClient', 'salesClient', 'specialist', 'service');
    }

    private static function createBookingInProcess(
        int $organizationId,
        int $clientId,
        int $specialistId,
        int $serviceId,
        string $startsAt,
    ): string {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            app(CreateBooking::class)->handle(
                actor: Client::query()->findOrFail($clientId),
                client: Client::query()->findOrFail($clientId),
                specialist: Specialist::query()->findOrFail($specialistId),
                service: Service::query()->findOrFail($serviceId),
                startsAt: CarbonImmutable::parse($startsAt),
                format: VisitFormat::Office,
                idempotencyKey: 'pg-race-booking',
            );

            return 'booking';
        } catch (ValidationException $exception) {
            return self::isSchedulingConflict($exception) ? 'booking-conflict' : 'booking-validation-error';
        } catch (Throwable) {
            return 'booking-error';
        }
    }

    private static function createSalesCallInProcess(
        int $organizationId,
        int $clientId,
        int $specialistId,
        string $startsAt,
    ): string {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $client = Client::query()->findOrFail($clientId);
            app(SubmitB2bLead::class)->handle(
                actor: $client,
                client: $client,
                specialist: Specialist::query()->findOrFail($specialistId),
                startsAt: CarbonImmutable::parse($startsAt),
                requestedTimezone: 'UTC',
                idempotencyKey: 'pg-race-b2b',
                source: B2bLeadSource::Portal,
                meetingMode: VideoMeetingMode::Manual,
                manualMeetingUrl: 'https://meet.example.test/pg-race-b2b',
            );

            return 'b2b';
        } catch (ValidationException $exception) {
            return self::isSchedulingConflict($exception) ? 'b2b-conflict' : 'b2b-validation-error';
        } catch (Throwable) {
            return 'b2b-error';
        }
    }

    private static function cancelSalesCallInProcess(
        int $organizationId,
        int $adminId,
        int $salesCallId,
        int $eventVersion,
    ): string {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            app(CancelB2bSalesCall::class)->handle(
                actor: User::query()->findOrFail($adminId),
                salesCall: B2bSalesCall::query()->findOrFail($salesCallId),
                expectedEventVersion: $eventVersion,
            );

            return 'cancelled';
        } catch (ValidationException $exception) {
            return ($exception->errors()['provider'][0] ?? null) === B2bProviderMutationGuard::BLOCKED_MESSAGE
                ? 'blocked'
                : 'validation-error';
        } catch (Throwable) {
            return 'error';
        }
    }

    private static function ownsProviderLeaseInProcess(
        int $organizationId,
        int $salesCallId,
        int $eventId,
        string $eventProcessingToken,
        string $leaseToken,
        int $eventVersion,
        int $providerSyncVersion,
        string $operation,
        string $providerDeadlineExpiresAt,
        string $leaseExpiresAt,
        int $requestSafetySeconds,
    ): string {
        $lease = new ProviderOperationLease(
            organizationId: $organizationId,
            salesCallId: $salesCallId,
            eventId: $eventId,
            eventProcessingToken: $eventProcessingToken,
            leaseToken: $leaseToken,
            eventVersion: $eventVersion,
            providerSyncVersion: $providerSyncVersion,
            operation: VideoMeetingOperation::from($operation),
            providerDeadlineExpiresAt: CarbonImmutable::parse($providerDeadlineExpiresAt)->utc(),
            leaseExpiresAt: CarbonImmutable::parse($leaseExpiresAt)->utc(),
            requestSafetySeconds: $requestSafetySeconds,
        );

        return app(B2bProviderLeaseManager::class)->owns($lease) ? 'owned' : 'lost';
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The B2B PostgreSQL integration tests require PostgreSQL.');
        }
    }

    private function constraintDefinition(string $constraintName): string
    {
        $constraint = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
            [$constraintName],
        );

        self::assertNotNull($constraint);

        return (string) $constraint->definition;
    }

    private static function isSchedulingConflict(ValidationException $exception): bool
    {
        $messages = $exception->errors();
        $expected = [
            'The selected time is no longer available.',
            'The selected sales-call time was taken concurrently.',
            'The selected sales-call time is no longer available.',
        ];

        return in_array('The selected time is no longer available.', $messages['startsAt'] ?? [], true)
            || count(array_intersect($expected, $messages['starts_at'] ?? [])) > 0;
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }
}
