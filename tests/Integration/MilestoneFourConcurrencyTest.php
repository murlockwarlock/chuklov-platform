<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\CreateBooking as CreateBookingAction;
use App\Modules\Scheduling\Application\RescheduleBooking;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MilestoneFourConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_two_real_parallel_transactions_cannot_both_insert_the_same_specialist_interval(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The race test requires PostgreSQL exclusion constraints.');
        }

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $start = CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC');
        $attributes = [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'specialist_id' => $specialist->getKey(),
            'service_id' => $service->getKey(),
            'visit_format' => 'office',
            'status' => BookingStatus::Requested->value,
            'payment_status' => 'unpaid',
            'source' => 'portal',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->addHour()->toIso8601String(),
            'blocking_ends_at' => $start->addMinutes(75)->toIso8601String(),
            'schedule_timezone' => 'UTC',
            'client_timezone' => 'UTC',
            'meeting_link_mode' => null,
            'meeting_url' => null,
            'party_size' => 1,
            'event_version' => 1,
            'requested_at' => now()->toIso8601String(),
            'cancelled_at' => null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::insertCompetingBooking($attributes, 'parallel-a'),
            static fn (): string => self::insertCompetingBooking($attributes, 'parallel-b'),
        ]);

        self::assertNotContains('error', array_map(
            static fn (string $result): string => str_starts_with($result, 'error:') ? 'error' : $result,
            $results,
        ), implode(', ', $results));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'inserted')));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'conflict')));
        self::assertSame(1, Booking::query()->where('organization_id', $organization->getKey())->count());
    }

    public function test_two_real_parallel_reschedules_from_the_same_event_version_have_one_winner(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The race test requires PostgreSQL row locks.');
        }

        [$organization, $admin, $client, $specialist, $service] = $this->schedulingFixture();
        $start = CarbonImmutable::create(2027, 4, 5, 9, 0, 0, 'UTC');
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => $start,
                'ends_at' => $start->addHour(),
                'blocking_ends_at' => $start->addMinutes(75),
                'schedule_timezone' => 'UTC',
                'client_timezone' => 'UTC',
                'status' => BookingStatus::Requested,
            ]);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::rescheduleInProcess(
                $organization->getKey(),
                $admin->getKey(),
                $booking->getKey(),
                $start->addMinutes(75)->toIso8601String(),
                1,
            ),
            static fn (): string => self::rescheduleInProcess(
                $organization->getKey(),
                $admin->getKey(),
                $booking->getKey(),
                $start->addMinutes(225)->toIso8601String(),
                1,
            ),
        ]);

        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'rescheduled')));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'stale')));
        self::assertSame(2, $booking->fresh()->event_version);
        self::assertSame(1, BookingEvent::query()
            ->where('booking_id', $booking->getKey())
            ->where('event_type', 'rescheduled')
            ->count());
        self::assertSame(1, AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('action', 'booking.rescheduled')
            ->count());
    }

    public function test_two_real_parallel_same_key_creations_create_one_booking_and_one_event(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The race test requires PostgreSQL idempotency and exclusion constraints.');
        }

        [$organization, , $client, $specialist, $service] = $this->schedulingFixture();
        $start = CarbonImmutable::create(2027, 4, 5, 9, 0, 0, 'UTC');

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::createInProcess(
                $organization->getKey(),
                $client->getKey(),
                $specialist->getKey(),
                $service->getKey(),
                $start->toIso8601String(),
                'same-process-key',
            ),
            static fn (): string => self::createInProcess(
                $organization->getKey(),
                $client->getKey(),
                $specialist->getKey(),
                $service->getKey(),
                $start->toIso8601String(),
                'same-process-key',
            ),
        ]);

        self::assertCount(2, $results);
        self::assertSame(2, count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'booking:'))));
        self::assertSame(1, Booking::query()->where('organization_id', $organization->getKey())->count());
        self::assertSame(1, BookingEvent::query()->count());
        self::assertSame(1, DB::table('booking_idempotency_keys')->where('organization_id', $organization->getKey())->count());
    }

    public function test_postgresql_booking_events_cannot_be_deleted(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The immutable history trigger requires PostgreSQL.');
        }

        [$organization, , $client, $specialist, $service] = $this->schedulingFixture();
        $booking = app(CreateBookingAction::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2027, 4, 5, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'immutable-history-delete',
        );
        $event = BookingEvent::query()->where('booking_id', $booking->getKey())->sole();

        $deleted = false;
        try {
            DB::table('booking_events')->where('id', $event->getKey())->delete();
            $deleted = true;
        } catch (QueryException) {
            $deleted = false;
        }

        self::assertFalse($deleted);
        self::assertDatabaseHas('booking_events', [
            'organization_id' => $organization->getKey(),
            'id' => $event->getKey(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private static function insertCompetingBooking(array $attributes, string $uid): string
    {
        $attributes['calendar_uid'] = $uid;

        try {
            DB::transaction(function () use ($attributes): void {
                DB::table('bookings')->insert($attributes);
                DB::select('SELECT pg_sleep(1)');
            });

            return 'inserted';
        } catch (\Throwable $exception) {
            if ($exception instanceof QueryException
                && in_array($exception->getCode() ?: ($exception->errorInfo[0] ?? null), ['23P01', '40P01'], true)) {
                return 'conflict';
            }

            return 'error:'.get_class($exception).':'.(string) $exception->getCode().':'.$exception->getMessage();
        }
    }

    /** @return array{Organization, User, Client, Specialist, Service} */
    private function schedulingFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'formats' => ['office'],
        ]);
        app(OrganizationContext::class)->set($organization);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]]);

        return [$organization, $admin, $client, $specialist, $service];
    }

    private static function rescheduleInProcess(
        int $organizationId,
        int $adminId,
        int $bookingId,
        string $startsAt,
        int $expectedVersion,
    ): string {
        $organization = Organization::query()->findOrFail($organizationId);
        app(OrganizationContext::class)->set($organization);

        try {
            app(RescheduleBooking::class)->handle(
                actor: User::query()->findOrFail($adminId),
                booking: Booking::query()->findOrFail($bookingId),
                newStartsAt: CarbonImmutable::parse($startsAt),
                expectedEventVersion: $expectedVersion,
            );

            return 'rescheduled';
        } catch (ValidationException $exception) {
            return array_key_exists('expected_event_version', $exception->errors()) ? 'stale' : 'error';
        }
    }

    private static function createInProcess(
        int $organizationId,
        int $clientId,
        int $specialistId,
        int $serviceId,
        string $startsAt,
        string $idempotencyKey,
    ): string {
        $organization = Organization::query()->findOrFail($organizationId);
        app(OrganizationContext::class)->set($organization);
        $client = Client::query()->findOrFail($clientId);

        $booking = app(CreateBookingAction::class)->handle(
            actor: $client,
            client: $client,
            specialist: Specialist::query()->findOrFail($specialistId),
            service: Service::query()->findOrFail($serviceId),
            startsAt: CarbonImmutable::parse($startsAt),
            format: VisitFormat::Office,
            idempotencyKey: $idempotencyKey,
        );

        return 'booking:'.$booking->getKey();
    }
}
