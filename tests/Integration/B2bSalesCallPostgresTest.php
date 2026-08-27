<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\B2B\Application\SubmitB2bLead;
use App\Modules\B2B\Domain\Enums\B2bLeadSource;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\Broadcasts\Application\SetClientB2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
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
        } catch (ValidationException) {
            return 'booking-conflict';
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
            );

            return 'b2b';
        } catch (ValidationException) {
            return 'b2b-conflict';
        } catch (Throwable) {
            return 'b2b-error';
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The B2B PostgreSQL integration tests require PostgreSQL.');
        }
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }
}
