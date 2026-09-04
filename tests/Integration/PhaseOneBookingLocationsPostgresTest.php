<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\UpdateWorkingLocation;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PhaseOneBookingLocationsPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_location_schema_enforces_tenant_safe_defaults_and_booking_shape(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $otherOrganization = Organization::factory()->create(['timezone' => 'Europe/Berlin']);
        $default = WorkingLocation::factory()->forOrganization($organization)->defaultOffice()->create([
            'timezone' => 'Asia/Almaty',
        ]);
        $second = WorkingLocation::factory()->forOrganization($organization)->create([
            'name' => 'Second office',
            'address' => 'Second address',
        ]);
        $otherLocation = WorkingLocation::factory()->forOrganization($otherOrganization)->create([
            'timezone' => 'Europe/Berlin',
        ]);

        $this->assertQueryException(function () use ($organization): void {
            WorkingLocation::factory()->forOrganization($organization)->defaultOffice()->create();
        }, 'A second default office location was accepted.');

        $this->assertQueryException(function () use ($organization): void {
            WorkingLocation::factory()->forOrganization($organization)->create(['timezone' => '']);
        }, 'An empty working-location timezone was accepted.');

        $this->assertQueryException(function () use ($organization): void {
            WorkingLocation::factory()->forOrganization($organization)->inactive()->defaultOffice()->create();
        }, 'An inactive default office location was accepted.');

        $admin = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        $updated = app(UpdateWorkingLocation::class)->handle(
            actor: $admin,
            location: $second,
            name: $second->name,
            address: $second->address,
            timezone: $second->timezone,
            isActive: true,
            isDefaultOffice: true,
        );
        self::assertTrue($updated->is_default_office);
        self::assertFalse($default->refresh()->is_default_office);

        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();

        $this->assertQueryException(function () use ($client, $otherLocation, $service, $specialist): void {
            Booking::factory()
                ->forClient($client)
                ->forSpecialist($specialist)
                ->forService($service)
                ->create([
                    'working_location_id' => $otherLocation->getKey(),
                    'location' => $otherLocation->address,
                    'location_snapshot' => [
                        'type' => 'office',
                        'name' => $otherLocation->name,
                        'address' => $otherLocation->address,
                        'timezone' => $otherLocation->timezone,
                    ],
                ]);
        }, 'A cross-organization working location was accepted by a booking.');

        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'visit_format' => 'office',
                'working_location_id' => $updated->getKey(),
                'location' => $updated->address,
                'location_snapshot' => [
                    'type' => 'office',
                    'name' => $updated->name,
                    'address' => $updated->address,
                    'timezone' => $updated->timezone,
                ],
            ]);

        $this->assertQueryException(
            fn (): bool => $updated->delete(),
            'A working location referenced by a booking was deleted.',
        );
        self::assertModelExists($booking);

        self::assertDatabaseHas('working_locations', [
            'id' => $default->getKey(),
            'organization_id' => $organization->getKey(),
            'is_default_office' => false,
        ]);
        self::assertDatabaseHas('working_locations', [
            'id' => $updated->getKey(),
            'organization_id' => $organization->getKey(),
            'is_default_office' => true,
        ]);
    }

    public function test_postgresql_legacy_address_backfill_is_idempotent_and_does_not_rewrite_booking_history(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        DB::table('organization_settings')->insert([
            'organization_id' => $organization->getKey(),
            'setting_key' => 'office_location',
            'value_type' => 'string',
            'string_value' => 'ул. Абая, 10',
            'integer_value' => null,
            'boolean_value' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'location' => 'ул. Абая, 10',
                'working_location_id' => null,
                'location_snapshot' => null,
            ]);

        $migration = require database_path('migrations/2026_09_03_205012_backfill_working_locations_and_seed_home_visit_buffer.php');
        $migration->up();
        $migration->up();

        $locations = WorkingLocation::query()
            ->where('organization_id', $organization->getKey())
            ->get();
        self::assertCount(1, $locations);
        self::assertSame('ул. Абая, 10', $locations->sole()->address);
        self::assertSame('Asia/Almaty', $locations->sole()->timezone);
        self::assertTrue($locations->sole()->is_default_office);
        self::assertSame(1, DB::table('organization_settings')
            ->where('organization_id', $organization->getKey())
            ->where('setting_key', 'home_visit_occupied_buffer_minutes')
            ->count());
        self::assertNull($booking->refresh()->working_location_id);
        self::assertNull(DB::table('bookings')
            ->where('id', $booking->getKey())
            ->value('location_snapshot'));
    }

    public function test_postgresql_exclusion_constraint_uses_the_full_home_visit_cycle(): void
    {
        $this->requirePostgres();
        [$organization, $client, $specialist, $service] = $this->bookingFixture();
        $start = CarbonImmutable::create(2026, 9, 4, 3, 0, 0, 'UTC');
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Confirmed,
                'visit_format' => 'home',
                'starts_at' => $start,
                'ends_at' => $start->addHours(2),
                'blocking_ends_at' => $start->addMinutes(270),
                'schedule_timezone' => 'Asia/Bangkok',
                'client_timezone' => 'Europe/Berlin',
                'location_area' => 'Bang Tao',
                'location' => '123 Moo 5, Bang Tao',
            ]);

        self::assertSame('2026-09-04T07:30:00+00:00', $booking->blockingEndsAtUtc()->toIso8601String());

        $this->assertQueryException(function () use ($client, $service, $specialist, $start): void {
            Booking::factory()
                ->forClient($client)
                ->forSpecialist($specialist)
                ->forService($service)
                ->create([
                    'status' => BookingStatus::Requested,
                    'visit_format' => 'home',
                    'starts_at' => $start->addHours(2),
                    'ends_at' => $start->addHours(4),
                    'blocking_ends_at' => $start->addMinutes(390),
                    'schedule_timezone' => 'Asia/Bangkok',
                    'client_timezone' => 'Europe/Berlin',
                    'location_area' => 'Bang Tao',
                    'location' => '456 Moo 5, Bang Tao',
                ]);
        }, 'An overlapping home-visit cycle was accepted.');

        $nonOverlapping = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Requested,
                'visit_format' => 'home',
                'starts_at' => $start->addMinutes(270),
                'ends_at' => $start->addMinutes(390),
                'blocking_ends_at' => $start->addMinutes(540),
                'schedule_timezone' => 'Asia/Bangkok',
                'client_timezone' => 'Europe/Berlin',
                'location_area' => 'Bang Tao',
                'location' => '789 Moo 5, Bang Tao',
            ]);

        self::assertModelExists($nonOverlapping);
    }

    public function test_postgresql_parallel_home_visit_claims_cannot_both_insert_the_full_cycle(): void
    {
        $this->requirePostgres();
        [$organization, $client, $specialist, $service] = $this->bookingFixture();
        $start = CarbonImmutable::create(2026, 9, 11, 3, 0, 0, 'UTC');
        $attributes = [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'specialist_id' => $specialist->getKey(),
            'service_id' => $service->getKey(),
            'visit_format' => 'home',
            'status' => BookingStatus::Requested->value,
            'payment_status' => 'unpaid',
            'source' => 'portal',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->addHours(2)->toIso8601String(),
            'blocking_ends_at' => $start->addMinutes(270)->toIso8601String(),
            'schedule_timezone' => 'Asia/Bangkok',
            'client_timezone' => 'Europe/Berlin',
            'location' => '123 Moo 5, Bang Tao',
            'location_area' => 'Bang Tao',
            'location_snapshot' => json_encode([
                'type' => 'home',
                'area_name' => 'Bang Tao',
                'address' => '123 Moo 5, Bang Tao',
                'timezone' => 'Asia/Bangkok',
            ], JSON_THROW_ON_ERROR),
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
            static fn (): string => self::insertCompetingHomeVisit($attributes, 'phase-one-home-a'),
            static fn (): string => self::insertCompetingHomeVisit($attributes, 'phase-one-home-b'),
        ]);

        self::assertNotContains('error', array_map(
            static fn (string $result): string => str_starts_with($result, 'error:') ? 'error' : $result,
            $results,
        ), implode(', ', $results));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'inserted')));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'conflict')));
        self::assertSame(1, Booking::query()
            ->where('organization_id', $organization->getKey())
            ->where('visit_format', 'home')
            ->count());
    }

    /** @return array{Organization, Client, Specialist, Service} */
    private function bookingFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 120,
            'buffer_minutes' => 0,
            'formats' => ['home'],
        ]);

        return [$organization, $client, $specialist, $service];
    }

    /** @param array<string, mixed> $attributes */
    private static function insertCompetingHomeVisit(array $attributes, string $uid): string
    {
        $attributes['calendar_uid'] = $uid;

        try {
            DB::transaction(function () use ($attributes): void {
                DB::table('bookings')->insert($attributes);
                DB::select('SELECT pg_sleep(1)');
            });

            return 'inserted';
        } catch (QueryException $exception) {
            if (in_array($exception->getCode() ?: ($exception->errorInfo[0] ?? null), ['23P01', '40P01'], true)) {
                return 'conflict';
            }

            return 'error:'.get_class($exception).':'.(string) $exception->getCode().':'.$exception->getMessage();
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL is required for Phase 1 scheduling persistence coverage.');
        }
    }

    private function assertQueryException(Closure $callback, string $message): void
    {
        try {
            DB::transaction($callback);
        } catch (QueryException) {
            return;
        }

        self::fail($message);
    }
}
