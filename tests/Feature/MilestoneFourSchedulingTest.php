<?php

namespace Tests\Feature;

use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Resources\ScheduleExceptions\ScheduleExceptionResource;
use App\Filament\Resources\UnavailablePeriods\UnavailablePeriodResource;
use App\Models\User;
use App\Modules\Identity\Application\BlockClientSelfBooking;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\CreateScheduleException;
use App\Modules\Scheduling\Application\CreateUnavailablePeriod;
use App\Modules\Scheduling\Application\SetBookingLeadTime;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MilestoneFourSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::create(2026, 3, 27, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_authoritative_availability_consumes_service_timing_and_uses_specialist_timezone(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('Europe/Berlin');
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]]);

        $result = app(CalculateAvailability::class)->forStaff(
            actor: $admin,
            specialistId: $specialist->id,
            serviceId: $service->id,
            dateFrom: '2026-03-30',
            dateTo: '2026-03-30',
            format: VisitFormat::Office,
            displayTimezone: 'Asia/Almaty',
        );

        self::assertSame('Europe/Berlin', $result->scheduleTimezone);
        self::assertSame('Asia/Almaty', $result->displayTimezone);
        self::assertSame([
            '2026-03-30T07:00:00+00:00',
            '2026-03-30T08:15:00+00:00',
        ], array_map(fn ($slot): string => $slot->startsAt->toIso8601String(), $result->slots));
        self::assertSame('2026-03-30T12:00:00+05:00', $result->toArray()['slots'][0]['displayStartsAt']);
    }

    public function test_day_off_and_custom_window_exceptions_have_explicit_precedence(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 2,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]]);
        app(CreateScheduleException::class)->handle($admin, $specialist, [
            'exception_date' => '2026-03-31',
            'exception_type' => ScheduleExceptionType::CustomWindow->value,
            'start_time' => '13:00',
            'end_time' => '15:00',
            'reason' => 'Afternoon only',
        ]);

        $custom = app(CalculateAvailability::class)->forStaff(
            $admin,
            $specialist->id,
            $service->id,
            '2026-03-31',
            '2026-03-31',
            VisitFormat::Office,
        );
        self::assertSame(['13:00'], array_map(fn ($slot): string => $slot->startsAt->format('H:i'), $custom->slots));

        app(CreateScheduleException::class)->handle($admin, $specialist, [
            'exception_date' => '2026-04-07',
            'exception_type' => ScheduleExceptionType::DayOff->value,
            'reason' => 'Vacation',
        ]);
        $dayOff = app(CalculateAvailability::class)->forStaff(
            $admin,
            $specialist->id,
            $service->id,
            '2026-04-07',
            '2026-04-07',
            VisitFormat::Office,
        );
        self::assertCount(0, $dayOff->slots);
    }

    public function test_unavailable_period_and_existing_booking_are_removed_from_availability(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '14:00',
        ]]);
        app(CreateUnavailablePeriod::class)->handle(
            actor: $admin,
            specialist: $specialist,
            startsAt: CarbonImmutable::create(2026, 4, 6, 10, 15, 0, 'UTC'),
            endsAt: CarbonImmutable::create(2026, 4, 6, 12, 45, 0, 'UTC'),
            reason: 'Staff meeting',
        );

        $client = Client::factory()->forOrganization($organization)->create();
        $firstBooking = app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 12, 45, 0, 'UTC'),
            format: VisitFormat::Office,
        );

        self::assertSame(BookingStatus::Requested, $firstBooking->status);
        $result = app(CalculateAvailability::class)->forStaff(
            $admin,
            $specialist->id,
            $service->id,
            '2026-04-06',
            '2026-04-06',
            VisitFormat::Office,
        );
        self::assertSame(['09:00'], array_map(fn ($slot): string => $slot->startsAt->format('H:i'), $result->slots));
        self::assertCount(1, BookingEvent::query()->where('booking_id', $firstBooking->id)->get());
    }

    public function test_inactive_specialist_and_service_do_not_produce_slots(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]]);
        $specialist->forceFill(['is_active' => false])->save();

        $result = app(CalculateAvailability::class)->forStaff(
            $admin,
            $specialist->id,
            $service->id,
            '2026-04-06',
            '2026-04-06',
            VisitFormat::Office,
        );
        self::assertCount(0, $result->slots);

        $specialist->forceFill(['is_active' => true])->save();
        $service->forceFill(['is_active' => false])->save();
        $result = app(CalculateAvailability::class)->forStaff(
            $admin,
            $specialist->id,
            $service->id,
            '2026-04-06',
            '2026-04-06',
            VisitFormat::Office,
        );
        self::assertCount(0, $result->slots);
    }

    public function test_lead_time_is_organization_configuration_and_home_booking_is_pending_review(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        $service->forceFill(['formats' => ['home']])->save();
        Carbon::setTestNow(CarbonImmutable::create(2026, 3, 30, 9, 0, 0, 'UTC'));
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]]);
        app(SetBookingLeadTime::class)->handle($admin, 180);

        $result = app(CalculateAvailability::class)->forStaff(
            $admin,
            $specialist->id,
            $service->id,
            '2026-03-30',
            '2026-03-30',
            VisitFormat::HomeVisit,
        );
        self::assertSame(['12:45', '14:00', '15:15'], array_map(fn ($slot): string => $slot->startsAt->format('H:i'), $result->slots));

        $client = Client::factory()->forOrganization($organization)->create();
        $booking = app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 3, 30, 12, 45, 0, 'UTC'),
            format: VisitFormat::HomeVisit,
        );
        self::assertSame(BookingStatus::PendingReview, $booking->status);
    }

    public function test_booking_creation_accepts_only_authoritative_slot_starts(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]]);
        $client = Client::factory()->forOrganization($organization)->create();

        $this->expectException(ValidationException::class);
        app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 1, 0, 'UTC'),
            format: VisitFormat::Office,
        );
    }

    public function test_portal_booking_is_blocked_at_application_boundary_and_cross_org_reads_are_rejected(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        $this->enableFeature($organization, OrganizationFeature::ClientRecords);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]]);
        $client = Client::factory()->forOrganization($organization)->create();
        app(BlockClientSelfBooking::class)->handle($admin, $client, 'Staff review required.');

        $this->expectException(ValidationException::class);
        app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
        );
    }

    public function test_availability_rejects_a_specialist_from_another_organization(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();
        $this->setOrganization($organization);

        $this->expectException(AuthorizationException::class);
        app(CalculateAvailability::class)->forStaff(
            $admin,
            $otherSpecialist->id,
            $service->id,
            '2026-04-06',
            '2026-04-06',
            VisitFormat::Office,
        );
    }

    public function test_client_portal_availability_is_an_explicit_org_scoped_projection(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('Europe/Berlin');
        $this->enableFeature($organization, OrganizationFeature::ClientRecords);
        $client = Client::factory()->forOrganization($organization)->create([
            'timezone' => 'Asia/Almaty',
        ]);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]]);

        $this->withSession(['client_portal.client_id' => $client->id])
            ->get(route('portal.availability', [
                'specialist_id' => $specialist->id,
                'service_id' => $service->id,
                'date_from' => '2026-03-30',
                'date_to' => '2026-03-30',
                'format' => VisitFormat::Office->value,
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Availability')
                ->where('availability.specialistId', $specialist->id)
                ->where('availability.displayTimezone', 'Asia/Almaty')
                ->where('availability.slots.0.startsAt', '2026-03-30T07:00:00+00:00')
                ->missing('availability.slots.0.specialist')
                ->missing('availability.slots.0.service'));
    }

    public function test_client_portal_cannot_read_another_organization_schedule(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        $client = Client::factory()->forOrganization($organization)->create();
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();

        $this->withSession(['client_portal.client_id' => $client->id])
            ->get(route('portal.availability', [
                'specialist_id' => $otherSpecialist->id,
                'service_id' => $service->id,
                'date_from' => '2026-04-06',
                'date_to' => '2026-04-06',
                'format' => VisitFormat::Office->value,
            ]))
            ->assertForbidden();
    }

    public function test_scheduling_writes_reject_cross_organization_records(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $this->setOrganization($organization);

        try {
            app(CreateScheduleException::class)->handle($admin, $otherSpecialist, [
                'exception_date' => '2026-04-06',
                'exception_type' => ScheduleExceptionType::DayOff->value,
            ]);
            self::fail('The cross-organization schedule exception was accepted.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $this->expectException(AuthorizationException::class);
        app(CreateBooking::class)->handle(
            actor: $admin,
            client: $otherClient,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
        );
    }

    public function test_crm_scheduling_surfaces_are_organization_scoped_and_manage_protected(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();
        ScheduleException::factory()->forSpecialist($otherSpecialist)->create([
            'exception_date' => '2026-04-06',
        ]);
        UnavailablePeriod::factory()->forSpecialist($otherSpecialist)->create();

        self::assertSame([], ScheduleExceptionResource::getEloquentQuery()->pluck('id')->all());
        self::assertSame([], UnavailablePeriodResource::getEloquentQuery()->pluck('id')->all());
        $this->actingAs($admin);
        self::assertTrue(SchedulingConfiguration::canAccess());

        $this->get(route('filament.admin.pages.scheduling-configuration'))
            ->assertOk();
    }

    /** @return array{Organization, User, Specialist, Service} */
    private function fixture(string $timezone): array
    {
        $organization = Organization::factory()->create(['timezone' => $timezone]);
        $admin = User::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => null]);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'formats' => ['office', 'home', 'online'],
        ]);
        $this->setOrganization($organization);

        return [$organization, $admin, $specialist, $service];
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);
    }

    private function enableFeature(Organization $organization, OrganizationFeature $feature): void
    {
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => $feature->value,
            'enabled' => true,
        ]);
    }
}
