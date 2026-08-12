<?php

namespace Tests\Integration;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MilestoneFourDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_uses_timezone_aware_booking_instants_and_btree_gist_extension(): void
    {
        self::assertSame('timestamp with time zone', DB::selectOne(
            "SELECT data_type FROM information_schema.columns WHERE table_name = 'bookings' AND column_name = 'starts_at'"
        )->data_type);
        self::assertNotNull(DB::selectOne("SELECT installed_version FROM pg_available_extensions WHERE name = 'btree_gist'")->installed_version);
    }

    public function test_composite_booking_foreign_keys_reject_cross_organization_specialist(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();

        $this->expectException(QueryException::class);

        DB::table('bookings')->insert($this->bookingAttributes($organization->id, $client->id, $otherSpecialist->id, $service->id));
    }

    public function test_database_rejects_overlapping_recurring_hours_and_exceptions(): void
    {
        $organization = Organization::factory()->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        SpecialistWorkingHour::factory()->forSpecialist($specialist)->create([
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        try {
            DB::transaction(function () use ($specialist): void {
                SpecialistWorkingHour::factory()->forSpecialist($specialist)->create([
                    'weekday' => 1,
                    'start_time' => '11:00',
                    'end_time' => '13:00',
                ]);
            });
            self::fail('The overlapping working hour was accepted.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        ScheduleException::factory()->forSpecialist($specialist)->customWindow('10:00', '12:00')->create([
            'exception_date' => '2026-04-06',
        ]);

        $this->expectException(QueryException::class);
        ScheduleException::factory()->forSpecialist($specialist)->customWindow('11:00', '13:00')->create([
            'exception_date' => '2026-04-06',
        ]);
    }

    public function test_exclusion_constraint_blocks_overlapping_bookings_but_allows_cancelled_bookings(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $start = CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC');
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => $start,
                'ends_at' => $start->addHour(),
                'blocking_ends_at' => $start->addMinutes(75),
            ]);

        try {
            DB::transaction(function () use ($client, $specialist, $service, $start): void {
                Booking::factory()
                    ->forClient($client)
                    ->forSpecialist($specialist)
                    ->forService($service)
                    ->create([
                        'starts_at' => $start->addMinutes(30),
                        'ends_at' => $start->addMinutes(90),
                        'blocking_ends_at' => $start->addMinutes(105),
                    ]);
            });
            self::fail('The overlapping booking was accepted.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $cancelled = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Cancelled->value,
                'starts_at' => $start->addMinutes(30),
                'ends_at' => $start->addMinutes(90),
                'blocking_ends_at' => $start->addMinutes(105),
            ]);

        self::assertSame(BookingStatus::Cancelled, $cancelled->status);
        self::assertModelExists($booking);
    }

    public function test_unavailable_period_range_is_database_protected(): void
    {
        $organization = Organization::factory()->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $period = UnavailablePeriod::factory()->forSpecialist($specialist)->create([
            'starts_at' => CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            'ends_at' => CarbonImmutable::create(2026, 4, 6, 10, 0, 0, 'UTC'),
        ]);

        $this->expectException(QueryException::class);
        UnavailablePeriod::factory()->forSpecialist($specialist)->create([
            'starts_at' => CarbonImmutable::create(2026, 4, 6, 9, 30, 0, 'UTC'),
            'ends_at' => CarbonImmutable::create(2026, 4, 6, 10, 30, 0, 'UTC'),
        ]);
        unset($period);
    }

    public function test_booking_event_history_is_immutable(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $event = BookingEvent::factory()->forBooking($booking)->create();

        $this->expectException(QueryException::class);
        $event->forceFill(['reason' => 'Mutation'])->save();
    }

    public function test_booking_meeting_metadata_is_online_only(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();

        $this->expectException(QueryException::class);
        Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'visit_format' => 'office',
                'meeting_link_mode' => 'auto',
            ]);
    }

    /** @return array<string, mixed> */
    private function bookingAttributes(int $organizationId, int $clientId, int $specialistId, int $serviceId): array
    {
        $start = CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC');

        return [
            'organization_id' => $organizationId,
            'client_id' => $clientId,
            'specialist_id' => $specialistId,
            'service_id' => $serviceId,
            'calendar_uid' => fake()->uuid(),
            'visit_format' => 'office',
            'status' => 'requested',
            'payment_status' => 'unpaid',
            'source' => 'crm',
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'blocking_ends_at' => $start->addMinutes(60),
            'schedule_timezone' => 'UTC',
            'client_timezone' => null,
            'location' => null,
            'meeting_link_mode' => null,
            'meeting_url' => null,
            'party_size' => 1,
            'event_version' => 1,
            'requested_at' => now(),
            'cancelled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
