<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\ApproveHomeVisitBooking;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\CancelBooking;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\CreateScheduleException;
use App\Modules\Scheduling\Application\CreateUnavailablePeriod;
use App\Modules\Scheduling\Application\MarkBookingNoShow;
use App\Modules\Scheduling\Application\RemoveSpecialistServiceAssignment;
use App\Modules\Scheduling\Application\RescheduleBooking;
use App\Modules\Scheduling\Application\SetOnlineMeetingUrl;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Application\UpdateClientTimezonePreference;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\PaymentRequirementType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Application\UpdateService;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Application\SetSpecialistActive;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MilestoneFourFinalLifecycleTest extends TestCase
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

    public function test_same_booking_request_key_replays_the_same_booking_and_rejects_payload_reuse(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $startsAt = CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC');

        $first = app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: $startsAt,
            format: VisitFormat::Office,
            idempotencyKey: 'client-retry-1',
        );
        $replay = app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: $startsAt,
            format: VisitFormat::Office,
            idempotencyKey: 'client-retry-1',
        );

        self::assertSame($first->id, $replay->id);
        self::assertSame(1, Booking::query()->count());
        self::assertSame(1, BookingEvent::query()->where('booking_id', $first->id)->count());

        $this->expectException(ValidationException::class);
        app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: $startsAt->addMinutes(75),
            format: VisitFormat::Office,
            idempotencyKey: 'client-retry-1',
        );
    }

    public function test_idempotency_key_cannot_be_reused_by_another_client(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $firstClient = Client::factory()->forOrganization($organization)->create();
        $secondClient = Client::factory()->forOrganization($organization)->create();
        $startsAt = CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC');

        app(CreateBooking::class)->handle(
            actor: $firstClient,
            client: $firstClient,
            specialist: $specialist,
            service: $service,
            startsAt: $startsAt,
            format: VisitFormat::Office,
            idempotencyKey: 'shared-key',
        );

        $this->expectException(AuthorizationException::class);
        app(CreateBooking::class)->handle(
            actor: $secondClient,
            client: $secondClient,
            specialist: $specialist,
            service: $service,
            startsAt: $startsAt->addMinutes(75),
            format: VisitFormat::Office,
            idempotencyKey: 'shared-key',
        );
    }

    public function test_client_cutoff_staff_override_and_payment_separation_are_enforced(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');
        Carbon::setTestNow(CarbonImmutable::create(2026, 4, 5, 12, 0, 0, 'UTC'));

        try {
            app(CancelBooking::class)->handle($client, $booking);
            self::fail('The client cancelled inside the cutoff.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('booking', $exception->errors());
        }

        $cancelled = app(CancelBooking::class)->handle($admin, $booking, 'Staff override inside cutoff.');

        self::assertSame(BookingStatus::Cancelled, $cancelled->status);
        self::assertSame('unpaid', $cancelled->payment_status->value);
        self::assertSame(BookingEventType::Cancelled, $cancelled->events()->latest('id')->firstOrFail()->event_type);
    }

    public function test_reschedule_preserves_booking_identity_and_records_old_and_new_time(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'Europe/Berlin']);
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');
        $calendarUid = $booking->calendar_uid;
        Carbon::setTestNow(CarbonImmutable::create(2026, 4, 1, 12, 0, 0, 'UTC'));

        $rescheduled = app(RescheduleBooking::class)->handle(
            actor: $client,
            booking: $booking,
            newStartsAt: CarbonImmutable::create(2026, 4, 6, 10, 15, 0, 'UTC'),
            clientTimezone: 'Europe/Berlin',
            expectedEventVersion: $booking->event_version,
        );

        self::assertSame($booking->id, $rescheduled->id);
        self::assertSame($calendarUid, $rescheduled->calendar_uid);
        self::assertSame('2026-04-06T10:15:00+00:00', $rescheduled->startsAtUtc()->toIso8601String());
        self::assertSame(2, $rescheduled->event_version);
        self::assertSame(BookingEventType::Rescheduled, $rescheduled->events()->latest('id')->firstOrFail()->event_type);
        self::assertSame('2026-04-06T09:00:00+00:00', $rescheduled->events()->latest('id')->firstOrFail()->old_values['starts_at']);
    }

    public function test_reschedule_conflict_preserves_the_original_booking_time(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $firstClient = Client::factory()->forOrganization($organization)->create();
        $secondClient = Client::factory()->forOrganization($organization)->create();
        $firstBooking = $this->createBooking($admin, $firstClient, $specialist, $service, '2026-04-06 09:00:00');
        $this->createBooking($admin, $secondClient, $specialist, $service, '2026-04-06 10:15:00');

        $this->expectException(ValidationException::class);
        try {
            app(RescheduleBooking::class)->handle(
                actor: $admin,
                booking: $firstBooking,
                newStartsAt: CarbonImmutable::create(2026, 4, 6, 10, 15, 0, 'UTC'),
                expectedEventVersion: $firstBooking->event_version,
            );
        } finally {
            self::assertSame('2026-04-06T09:00:00+00:00', $firstBooking->fresh()->startsAtUtc()->toIso8601String());
            self::assertSame(1, $firstBooking->fresh()->event_version);
        }
    }

    public function test_staff_reschedule_inside_cutoff_requires_reason(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');
        Carbon::setTestNow(CarbonImmutable::create(2026, 4, 5, 12, 0, 0, 'UTC'));

        try {
            app(RescheduleBooking::class)->handle(
                actor: $admin,
                booking: $booking,
                newStartsAt: CarbonImmutable::create(2026, 4, 6, 10, 15, 0, 'UTC'),
                expectedEventVersion: $booking->event_version,
            );
            self::fail('A staff reschedule inside the cutoff did not require a reason.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reason', $exception->errors());
        }

        $rescheduled = app(RescheduleBooking::class)->handle(
            actor: $admin,
            booking: $booking,
            newStartsAt: CarbonImmutable::create(2026, 4, 6, 10, 15, 0, 'UTC'),
            reason: 'Staff requested a new time.',
            expectedEventVersion: $booking->event_version,
        );

        self::assertSame('2026-04-06T10:15:00+00:00', $rescheduled->startsAtUtc()->toIso8601String());
    }

    public function test_pending_home_visit_can_be_withdrawn_without_cutoff_and_does_not_change_payment(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture(['home']);
        $client = Client::factory()->forOrganization($organization)->create();
        $pending = app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::HomeVisit,
            idempotencyKey: 'withdraw-'.$client->id,
        );
        Carbon::setTestNow(CarbonImmutable::create(2026, 4, 5, 23, 0, 0, 'UTC'));

        $withdrawn = app(CancelBooking::class)->handle($client, $pending);

        self::assertSame(BookingStatus::Cancelled, $withdrawn->status);
        self::assertSame('unpaid', $withdrawn->payment_status->value);
    }

    public function test_confirm_complete_and_no_show_are_typed_authorized_terminal_outcomes(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');

        try {
            app(MarkBookingNoShow::class)->handle($admin, $booking);
            self::fail('A future booking was marked no-show.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $confirmed = app(ConfirmBooking::class)->handle($admin, $booking);
        Carbon::setTestNow(CarbonImmutable::create(2026, 4, 6, 10, 30, 0, 'UTC'));
        $completed = app(CompleteBooking::class)->handle($admin, $confirmed);

        self::assertSame(BookingStatus::Completed, $completed->status);
        self::assertSame('unpaid', $completed->payment_status->value);
        self::assertSame(3, $completed->event_version);

        $second = $this->createBooking($admin, Client::factory()->forOrganization($organization)->create(), $specialist, $service, '2026-04-13 09:00:00');
        $second = app(ConfirmBooking::class)->handle($admin, $second);
        Carbon::setTestNow(CarbonImmutable::create(2026, 4, 13, 9, 1, 0, 'UTC'));
        $noShow = app(MarkBookingNoShow::class)->handle($admin, $second, 'Client did not attend.');

        self::assertSame(BookingStatus::NoShow, $noShow->status);
        self::assertSame('unpaid', $noShow->payment_status->value);
        self::assertSame(BookingEventType::NoShow, $noShow->events()->latest('id')->firstOrFail()->event_type);
    }

    public function test_home_approval_records_configured_transport_deposit_without_claiming_payment(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture(['home']);
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::HomeVisitTransportDepositAmountMinor,
            1500,
        );
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::HomeVisitTransportDepositCurrency,
            'THB',
        );
        $client = Client::factory()->forOrganization($organization)->create();
        $pending = app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::HomeVisit,
            idempotencyKey: 'deposit-'.$client->id,
        );

        $approved = app(ApproveHomeVisitBooking::class)->handle(
            actor: $admin,
            booking: $pending,
            paymentRequirement: PaymentRequirementType::TransportDeposit,
        );

        self::assertSame(PaymentRequirementType::TransportDeposit, $approved->payment_requirement);
        self::assertSame(1500, $approved->payment_requirement_amount_minor);
        self::assertSame('THB', $approved->payment_requirement_currency);
        self::assertSame('unpaid', $approved->payment_status->value);
    }

    public function test_online_manual_link_is_application_authorized_and_history_is_written(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture(['online']);
        $client = Client::factory()->forOrganization($organization)->create();
        $booking = app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Online,
            meetingLinkMode: MeetingLinkMode::Manual,
            idempotencyKey: 'online-'.$client->id,
        );
        $booking = app(ConfirmBooking::class)->handle($admin, $booking);
        $updated = app(SetOnlineMeetingUrl::class)->handle($admin, $booking, 'https://meet.example.test/room');

        self::assertSame('https://meet.example.test/room', $updated->meeting_url);
        self::assertSame(BookingEventType::MeetingLinkUpdated, $updated->events()->latest('id')->firstOrFail()->event_type);
    }

    public function test_schedule_mutations_require_acknowledgement_and_preserve_existing_booking(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');

        $impactDigest = null;
        try {
            app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
                'weekday' => 1,
                'start_time' => '10:00',
                'end_time' => '17:00',
            ]]);
            self::fail('A schedule mutation with an affected booking was accepted without acknowledgement.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('schedule_impact', $exception->errors());
            $impactDigest = (string) $exception->errors()['schedule_impact_digest'][0];
        }
        self::assertModelExists($booking->fresh());

        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '10:00',
            'end_time' => '17:00',
        ]], true, $impactDigest);
        self::assertSame('2026-04-06T09:00:00+00:00', $booking->fresh()->startsAtUtc()->toIso8601String());
    }

    public function test_exception_and_unavailable_period_impact_requires_acknowledgement(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');

        $exceptionImpactDigest = null;
        try {
            app(CreateScheduleException::class)->handle($admin, $specialist, [
                'exception_date' => '2026-04-06',
                'exception_type' => 'day_off',
            ]);
            self::fail('A day-off mutation was accepted without acknowledgement.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('schedule_impact', $exception->errors());
            $exceptionImpactDigest = (string) $exception->errors()['schedule_impact_digest'][0];
        }

        app(CreateScheduleException::class)->handle($admin, $specialist, [
            'exception_date' => '2026-04-06',
            'exception_type' => 'day_off',
        ], true, $exceptionImpactDigest);
        self::assertModelExists($booking->fresh());

        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');

        $unavailableImpactDigest = null;
        try {
            app(CreateUnavailablePeriod::class)->handle(
                actor: $admin,
                specialist: $specialist,
                startsAt: CarbonImmutable::create(2026, 4, 6, 8, 0, 0, 'UTC'),
                endsAt: CarbonImmutable::create(2026, 4, 6, 10, 0, 0, 'UTC'),
            );
            self::fail('An unavailable-period mutation was accepted without acknowledgement.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('schedule_impact', $exception->errors());
            $unavailableImpactDigest = (string) $exception->errors()['schedule_impact_digest'][0];
        }

        app(CreateUnavailablePeriod::class)->handle(
            actor: $admin,
            specialist: $specialist,
            startsAt: CarbonImmutable::create(2026, 4, 6, 8, 0, 0, 'UTC'),
            endsAt: CarbonImmutable::create(2026, 4, 6, 10, 0, 0, 'UTC'),
            acknowledgeImpact: true,
            acknowledgedImpactDigest: $unavailableImpactDigest,
        );
        self::assertModelExists($booking->fresh());
    }

    public function test_specialist_service_and_assignment_mutations_preserve_future_booking_history(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');

        $specialistImpactDigest = null;
        try {
            app(SetSpecialistActive::class)->handle($admin, $specialist, false);
            self::fail('Specialist deactivation was accepted without acknowledgement.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('schedule_impact', $exception->errors());
            $specialistImpactDigest = (string) $exception->errors()['schedule_impact_digest'][0];
        }
        app(SetSpecialistActive::class)->handle($admin, $specialist, false, true, $specialistImpactDigest);
        self::assertModelExists($booking->fresh());

        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');
        $attributes = [
            'name' => $service->name,
            'summary' => $service->summary,
            'is_active' => false,
            'catalog_type' => 'service',
            'duration_minutes' => $service->duration_minutes,
            'buffer_minutes' => $service->buffer_minutes,
            'formats' => $service->formats,
        ];

        $serviceImpactDigest = null;
        try {
            app(UpdateService::class)->handle($admin, $service, name: $attributes);
            self::fail('Service deactivation was accepted without acknowledgement.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('schedule_impact', $exception->errors());
            $serviceImpactDigest = (string) $exception->errors()['schedule_impact_digest'][0];
        }
        app(UpdateService::class)->handle($admin, $service, name: $attributes, acknowledgeImpact: true, acknowledgedImpactDigest: $serviceImpactDigest);
        self::assertModelExists($booking->fresh());

        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');
        $assignment = SpecialistServiceAssignment::query()
            ->where('organization_id', $organization->id)
            ->where('specialist_id', $specialist->id)
            ->where('service_id', $service->id)
            ->firstOrFail();

        $assignmentImpactDigest = null;
        try {
            app(RemoveSpecialistServiceAssignment::class)->handle($admin, $assignment);
            self::fail('Assignment removal was accepted without acknowledgement.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('schedule_impact', $exception->errors());
            $assignmentImpactDigest = (string) $exception->errors()['schedule_impact_digest'][0];
        }
        app(RemoveSpecialistServiceAssignment::class)->handle($admin, $assignment, true, $assignmentImpactDigest);
        self::assertModelExists($booking->fresh());
    }

    public function test_portal_my_bookings_is_client_and_organization_scoped(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'Europe/Berlin']);
        $booking = $this->createBooking($admin, $client, $specialist, $service, '2026-04-06 09:00:00');
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);

        $this->withSession(['client_portal.client_id' => $client->id])
            ->get(route('portal.bookings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/MyBookings')
                ->where('upcoming.0.id', $booking->id)
                ->where('upcoming.0.timezone', 'Europe/Berlin'));

        $this->withSession(['client_portal.client_id' => $client->id])
            ->get(route('portal.bookings.show', $booking->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/BookingShow')
                ->where('booking.id', $booking->id)
                ->where('booking.timezone', 'Europe/Berlin'));

        $this->withSession(['client_portal.client_id' => $otherClient->id])
            ->get(route('portal.bookings.show', $booking->id))
            ->assertNotFound();
    }

    public function test_client_timezone_preference_requires_an_iana_identifier_and_persists_through_application(): void
    {
        [$organization] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $this->setOrganization($organization);
        app(ClientPortalContext::class)->set($client);

        app(UpdateClientTimezonePreference::class)->handle('America/New_York');

        self::assertSame('America/New_York', $client->fresh()->timezone);

        $this->expectException(ValidationException::class);
        app(UpdateClientTimezonePreference::class)->handle('+05:00');
    }

    public function test_idempotency_key_is_organization_scoped(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'organization-key',
        );

        [$otherOrganization, $otherAdmin, $otherSpecialist, $otherService] = $this->fixture();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();

        $second = app(CreateBooking::class)->handle(
            actor: $otherClient,
            client: $otherClient,
            specialist: $otherSpecialist,
            service: $otherService,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'organization-key',
        );

        self::assertNotSame($second->id, Booking::query()->where('organization_id', $organization->id)->value('id'));
        self::assertSame(2, Booking::query()->count());
    }

    /** @param list<string> $formats */
    private function fixture(array $formats = ['office', 'home', 'online']): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'formats' => $formats,
        ]);
        $this->setOrganization($organization);
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

        return [$organization, $admin, $specialist, $service];
    }

    private function createBooking(
        User $admin,
        Client $client,
        Specialist $specialist,
        Service $service,
        string $startsAt,
    ): Booking {
        return app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::parse($startsAt, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'crm-'.$client->id.'-'.$startsAt,
        );
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);
    }
}
