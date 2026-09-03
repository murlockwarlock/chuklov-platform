<?php

namespace Tests\Feature;

use App\Filament\Pages\SchedulingConfiguration;
use App\Models\User;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Application\CreatePlatformLegalDocumentDraft;
use App\Modules\Identity\Application\PublishLegalDocument;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientBookingRestriction;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\BookingNeedsAttention;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\CreateScheduleException;
use App\Modules\Scheduling\Application\DeleteScheduleException;
use App\Modules\Scheduling\Application\RescheduleBooking;
use App\Modules\Scheduling\Application\SetBookingLeadTime;
use App\Modules\Scheduling\Application\SetOnlineMeetingUrl;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Application\UpdateClientTimezonePreference;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Application\UpdateSpecialist;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MilestoneFourRemediationTest extends TestCase
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

    public function test_portal_creation_generates_idempotency_key_at_the_application_boundary(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture(formats: ['office', 'home', 'online']);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $documents = [];
        foreach (['offer', 'privacy', 'medical_disclaimer'] as $documentType) {
            $documents[$documentType] = app(PublishLegalDocument::class)->handle(
                app(CreatePlatformLegalDocumentDraft::class)->handle(
                    organization: $organization,
                    documentType: $documentType,
                    purpose: $documentType.'_consent',
                    locale: 'en',
                    version: '2026-09-03-'.$documentType,
                    content: 'Synthetic legal fixture.',
                    isRequired: true,
                ),
            );
        }
        $consents = array_map(
            static fn (string $documentType): array => [
                'legal_document_id' => $documents[$documentType]->getKey(),
                'granted' => true,
            ],
            ['offer', 'privacy', 'medical_disclaimer'],
        );

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'starts_at' => '2026-04-06T09:00:00+00:00',
                'format' => VisitFormat::Office->value,
                'consents' => $consents,
                'idempotency_key' => 'client-controlled-key',
                'client_timezone' => 'America/New_York',
                'meeting_link_mode' => 'manual',
            ])
            ->assertRedirect();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'starts_at' => '2026-04-06T09:00:00+00:00',
                'format' => VisitFormat::Office->value,
            ])
            ->assertRedirect();

        self::assertSame(1, Booking::query()->where('organization_id', $organization->getKey())->count());
        self::assertSame(1, DB::table('booking_idempotency_keys')->where('organization_id', $organization->getKey())->count());
        self::assertNotSame(
            'client-controlled-key',
            DB::table('booking_idempotency_keys')->where('organization_id', $organization->getKey())->value('idempotency_key'),
        );
    }

    public function test_stale_reschedule_is_rejected_without_mutation_or_history(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = $this->createBooking($client, $specialist, $service, 'reschedule-stale');

        try {
            app(RescheduleBooking::class)->handle(
                actor: $admin,
                booking: $booking,
                newStartsAt: CarbonImmutable::create(2026, 4, 6, 10, 15, 0, 'UTC'),
                expectedEventVersion: 99,
            );
            self::fail('A stale reschedule was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('expected_event_version', $exception->errors());
        }

        $fresh = $booking->fresh();
        self::assertNotNull($fresh);
        self::assertSame(1, $fresh->event_version);
        self::assertSame('2026-04-06T09:00:00+00:00', $fresh->startsAtUtc()->toIso8601String());
        self::assertSame(1, $fresh->events()->count());
        self::assertSame(0, DB::table('audit_events')
            ->where('organization_id', $organization->getKey())
            ->where('action', 'booking.rescheduled')
            ->count());
    }

    public function test_idempotent_replay_survives_mutable_booking_state_changes(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'mutable-replay',
        );

        ClientBookingRestriction::factory()->forClient($client)->blockedBy($admin)->create([
            'organization_id' => $organization->getKey(),
        ]);
        SpecialistServiceAssignment::query()
            ->where('organization_id', $organization->getKey())
            ->where('specialist_id', $specialist->getKey())
            ->where('service_id', $service->getKey())
            ->delete();
        $specialist->forceFill(['is_active' => false])->save();
        $service->forceFill(['is_active' => false])->save();
        $client->forceFill(['timezone' => 'Asia/Almaty'])->save();
        OrganizationFeatureFlag::query()
            ->where('organization_id', $organization->getKey())
            ->where('feature_key', OrganizationFeature::ServiceCatalog->value)
            ->update(['enabled' => false]);

        $replay = app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'mutable-replay',
        );

        self::assertSame($booking->getKey(), $replay->getKey());
        self::assertSame(1, $booking->events()->count());
    }

    public function test_pending_home_visit_creation_replays_without_a_second_request_event(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture(formats: ['office', 'home', 'online']);
        $first = app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::HomeVisit,
            idempotencyKey: 'pending-home-retry',
            location: 'Client destination',
        );
        $replay = app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::HomeVisit,
            idempotencyKey: 'pending-home-retry',
            location: 'Client destination',
        );

        self::assertSame($first->getKey(), $replay->getKey());
        self::assertSame('pending_review', $replay->status->value);
        self::assertSame(1, $first->events()->count());
        self::assertSame(1, Booking::query()->where('organization_id', $organization->getKey())->count());
    }

    public function test_service_catalog_feature_gate_blocks_direct_new_booking_paths_but_not_history(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $booking = $this->createBooking($client, $specialist, $service, 'feature-history');
        OrganizationFeatureFlag::query()
            ->where('organization_id', $organization->getKey())
            ->where('feature_key', OrganizationFeature::ServiceCatalog->value)
            ->update(['enabled' => false]);
        $this->setOrganization($organization);

        try {
            app(CalculateAvailability::class)->forClient(
                client: $client,
                specialistId: $specialist->getKey(),
                serviceId: $service->getKey(),
                dateFrom: '2026-04-06',
                dateTo: '2026-04-06',
                format: VisitFormat::Office,
            );
            self::fail('Direct availability was exposed with the catalog disabled.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.availability', [
                'specialist_id' => $specialist->getKey(),
                'service_id' => $service->getKey(),
                'date_from' => '2026-04-06',
                'date_to' => '2026-04-06',
                'format' => VisitFormat::Office->value,
            ]))
            ->assertForbidden();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.show', $booking->getKey()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('booking.id', $booking->getKey()));
    }

    public function test_schedule_impact_acknowledgement_is_bound_to_the_current_booking_set(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = $this->createBooking($client, $specialist, $service, 'impact-digest');
        $definition = [[
            'weekday' => 1,
            'start_time' => '10:00',
            'end_time' => '17:00',
        ]];

        try {
            app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, $definition);
            self::fail('Impact preview was not required.');
        } catch (ValidationException $exception) {
            $digest = (string) $exception->errors()['schedule_impact_digest'][0];
        }

        $booking->forceFill(['event_version' => 2])->save();

        try {
            app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, $definition, true, $digest);
            self::fail('A stale impact acknowledgement was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('schedule_impact_digest', $exception->errors());
        }

        self::assertSame('09:00', substr((string) $booking->fresh()->starts_at, 11, 5));
    }

    public function test_deleting_the_only_custom_window_requires_impact_acknowledgement(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '13:00',
            'end_time' => '17:00',
        ]]);
        $exception = app(CreateScheduleException::class)->handle($admin, $specialist, [
            'exception_date' => '2026-04-06',
            'exception_type' => ScheduleExceptionType::CustomWindow->value,
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);
        $booking = $this->createBooking($client, $specialist, $service, 'exception-delete');

        try {
            app(DeleteScheduleException::class)->handle($admin, $exception);
            self::fail('Exception deletion was accepted without impact acknowledgement.');
        } catch (ValidationException $validationException) {
            $digest = (string) $validationException->errors()['schedule_impact_digest'][0];
        }

        self::assertModelExists($exception->fresh());
        app(DeleteScheduleException::class)->handle($admin, $exception, true, $digest);
        self::assertModelMissing($exception);
        self::assertModelExists($booking->fresh());
    }

    public function test_needs_attention_uses_existing_booking_alignment_not_current_lead_time(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = $this->createBooking($client, $specialist, $service, 'needs-attention');

        self::assertFalse(app(BookingNeedsAttention::class)->handle($booking));
        app(SetBookingLeadTime::class)->handle($admin, 1000);
        self::assertFalse(app(BookingNeedsAttention::class)->handle($booking->fresh()));

        $service->forceFill(['duration_minutes' => 75])->save();
        self::assertTrue(app(BookingNeedsAttention::class)->handle($booking->fresh()));
        $service->forceFill(['duration_minutes' => 60])->save();
        $specialist->forceFill(['timezone' => 'Europe/Berlin'])->save();
        self::assertTrue(app(BookingNeedsAttention::class)->handle($booking->fresh()));
        $specialist->forceFill(['timezone' => 'UTC'])->save();
        SpecialistServiceAssignment::query()
            ->where('organization_id', $organization->getKey())
            ->where('specialist_id', $specialist->getKey())
            ->where('service_id', $service->getKey())
            ->delete();
        self::assertTrue(app(BookingNeedsAttention::class)->handle($booking->fresh()));
    }

    public function test_client_timezone_preference_changes_projection_without_rewriting_booking_metadata(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture(clientTimezone: 'Europe/Berlin');
        $booking = $this->createBooking($client, $specialist, $service, 'timezone-history');
        app(ClientPortalContext::class)->set($client);
        app(UpdateClientTimezonePreference::class)->handle('Asia/Almaty');
        $this->setOrganization($organization);

        self::assertSame('Europe/Berlin', $booking->fresh()->client_timezone);
        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.show', $booking->getKey()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('booking.timezone', 'Asia/Almaty')
                ->where('client.timezone', 'Asia/Almaty'));
    }

    public function test_display_date_filtering_uses_client_calendar_date_across_large_offsets(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture(
            specialistTimezone: 'Pacific/Kiritimati',
            clientTimezone: 'America/Adak',
        );
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '10:15',
        ]]);
        $result = app(CalculateAvailability::class)->forClient(
            client: $client,
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            dateFrom: '2026-03-29',
            dateTo: '2026-03-29',
            format: VisitFormat::Office,
        );

        self::assertCount(1, $result->slots);
        self::assertSame('2026-03-29', $result->slots[0]->startsAt->setTimezone('America/Adak')->toDateString());
    }

    public function test_display_date_filtering_handles_the_opposite_large_offset_direction(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture(
            specialistTimezone: 'America/Adak',
            clientTimezone: 'Pacific/Kiritimati',
        );
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '10:15',
        ]]);
        $result = app(CalculateAvailability::class)->forClient(
            client: $client,
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            dateFrom: '2026-03-31',
            dateTo: '2026-03-31',
            format: VisitFormat::Office,
        );

        self::assertCount(1, $result->slots);
        self::assertSame('2026-03-31', $result->slots[0]->startsAt->setTimezone('Pacific/Kiritimati')->toDateString());
    }

    public function test_manual_online_meeting_url_rejects_non_http_schemes(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture(formats: ['online']);
        $booking = app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Online,
            meetingLinkMode: MeetingLinkMode::Manual,
            idempotencyKey: 'bad-url',
        );

        try {
            app(SetOnlineMeetingUrl::class)->handle($admin, $booking, 'ftp://example.test/room');
            self::fail('An FTP meeting URL was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('meetingUrl', $exception->errors());
        }
    }

    public function test_specialist_schedule_impact_audit_labels_match_the_changed_fields(): void
    {
        [$timezoneOrganization, $timezoneAdmin, , $timezoneSpecialist] = $this->fixture();
        self::assertSame('specialist_timezone', $this->updateSpecialistWithImpact(
            $timezoneOrganization,
            $timezoneAdmin,
            $timezoneSpecialist,
            true,
            'Europe/Berlin',
            'timezone-only',
        ));

        [$deactivationOrganization, $deactivationAdmin, , $deactivationSpecialist] = $this->fixture();
        self::assertSame('specialist_deactivation', $this->updateSpecialistWithImpact(
            $deactivationOrganization,
            $deactivationAdmin,
            $deactivationSpecialist,
            false,
            'UTC',
            'deactivation-only',
        ));

        [$combinedOrganization, $combinedAdmin, , $combinedSpecialist] = $this->fixture();
        self::assertSame('specialist_deactivation_and_timezone', $this->updateSpecialistWithImpact(
            $combinedOrganization,
            $combinedAdmin,
            $combinedSpecialist,
            false,
            'Europe/Berlin',
            'deactivation-and-timezone',
        ));
    }

    public function test_combined_scheduling_configuration_save_rolls_back_when_working_hours_acknowledgement_fails(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->createBooking($client, $specialist, $service, 'atomic-config');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(SchedulingConfiguration::class)
            ->fillForm([
                'specialist_id' => $specialist->getKey(),
                'lead_time_minutes' => 123,
                'cancellation_cutoff_minutes' => 456,
                'office_location' => 'New office',
                'working_hours' => [[
                    'weekday' => 1,
                    'start_time' => '10:00',
                    'end_time' => '17:00',
                ]],
                'acknowledge_impact' => false,
                'impact_digest' => null,
            ])
            ->call('save')
            ->assertHasErrors('schedule_impact');

        self::assertSame(0, (int) DB::table('organization_settings')
            ->where('organization_id', $organization->getKey())
            ->where('setting_key', 'booking_lead_time_minutes')
            ->value('integer_value'));
        self::assertSame(0, DB::table('organization_settings')
            ->where('organization_id', $organization->getKey())
            ->where('setting_key', 'booking_cancellation_cutoff_minutes')
            ->count());
        self::assertSame(0, DB::table('organization_settings')
            ->where('organization_id', $organization->getKey())
            ->where('setting_key', 'office_location')
            ->count());
        self::assertSame('09:00', substr((string) $specialist->workingHours()->firstOrFail()->start_time, 0, 5));
    }

    /** @return array{Organization, User, Client, Specialist, Service} */
    private function fixture(
        string $specialistTimezone = 'UTC',
        string $clientTimezone = 'UTC',
        array $formats = ['office', 'online'],
    ): array {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => $clientTimezone]);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => $specialistTimezone]);
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

        return [$organization, $admin, $client, $specialist, $service];
    }

    private function createBooking(Client $client, Specialist $specialist, Service $service, string $key): Booking
    {
        return app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: $key,
        );
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    private function updateSpecialistWithImpact(
        Organization $organization,
        User $admin,
        Specialist $specialist,
        bool $isActive,
        string $timezone,
        string $key,
    ): string {
        $client = Client::factory()->forOrganization($organization)->create();
        $service = Service::query()->where('organization_id', $organization->getKey())->firstOrFail();
        $this->createBooking($client, $specialist, $service, $key);
        $digest = null;

        try {
            app(UpdateSpecialist::class)->handle(
                actor: $admin,
                specialist: $specialist,
                displayName: $specialist->display_name,
                isActive: $isActive,
                timezone: $timezone,
            );
            self::fail('A specialist schedule mutation was accepted without impact acknowledgement.');
        } catch (ValidationException $exception) {
            $digest = (string) $exception->errors()['schedule_impact_digest'][0];
        }

        app(UpdateSpecialist::class)->handle(
            actor: $admin,
            specialist: $specialist,
            displayName: $specialist->display_name,
            isActive: $isActive,
            timezone: $timezone,
            acknowledgeImpact: true,
            acknowledgedImpactDigest: $digest,
        );

        $metadata = json_decode((string) DB::table('audit_events')
            ->where('organization_id', $organization->getKey())
            ->where('action', 'schedule.mutation.acknowledged')
            ->latest('id')
            ->value('metadata'), true, flags: JSON_THROW_ON_ERROR);

        return (string) $metadata['mutation'];
    }
}
