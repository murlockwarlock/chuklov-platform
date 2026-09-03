<?php

namespace Tests\Feature;

use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\Identity\Application\CreatePlatformLegalDocumentDraft;
use App\Modules\Identity\Application\PublishLegalDocument;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PortalBookingRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::create(2026, 3, 15, 0, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_booking_create_projects_exact_major_prices_without_exposing_minor_units(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        DB::table('organization_allowed_currencies')->insert([
            [
                'organization_id' => $organization->getKey(),
                'currency' => 'JPY',
                'created_at' => now(),
            ],
            [
                'organization_id' => $organization->getKey(),
                'currency' => 'KZT',
                'created_at' => now(),
            ],
        ]);

        $service->forceFill([
            'price_minor' => 15_000,
            'price_currency' => 'JPY',
        ])->save();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.create', [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'format' => VisitFormat::Office->value,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('services.0.priceMajor', '15000')
                ->where('services.0.priceCurrency', 'JPY')
                ->missing('services.0.priceMinor'));

        $service->forceFill([
            'price_minor' => 1_500_050,
            'price_currency' => 'KZT',
        ])->save();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.create', [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'format' => VisitFormat::Office->value,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('services.0.priceMajor', '15000.50')
                ->where('services.0.priceCurrency', 'KZT')
                ->missing('services.0.priceMinor'));
    }

    public function test_normal_booking_uses_the_full_current_month_as_the_authoritative_availability_range(): void
    {
        [, $client, $specialist, $service] = $this->portalFixture();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.create', [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'format' => VisitFormat::Office->value,
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/BookingCreate')
                ->where('query.dateFrom', '2026-03-01')
                ->where('query.dateTo', '2026-03-31')
                ->where('availability.slots.0.startsAt', '2026-03-15T09:00:00+00:00')
                ->where('availability.slots.16.startsAt', '2026-03-31T09:00:00+00:00'));
    }

    public function test_ready_auto_online_booking_exposes_the_provider_join_url_to_the_portal(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture(['online']);
        $startsAt = CarbonImmutable::now()->addMinute();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Confirmed,
                'visit_format' => VisitFormat::Online,
                'meeting_link_mode' => MeetingLinkMode::Auto,
                'provider_sync_status' => VideoMeetingSyncStatus::Ready,
                'provider_join_url' => 'https://zoom.us/j/portal-ready',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addHour(),
                'blocking_ends_at' => $startsAt->addHour(),
                'schedule_timezone' => 'UTC',
                'client_timezone' => 'UTC',
            ]);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.show', $booking->getKey()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/BookingShow')
                ->where('booking.meetingUrl', 'https://zoom.us/j/portal-ready')
                ->where('booking.meetingPending', false)
                ->missing('booking.contactStaff'));
    }

    public function test_booking_month_navigation_keeps_the_authoritative_range_within_the_visible_month(): void
    {
        [, $client, $specialist, $service] = $this->portalFixture();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.create', [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'date_from' => '2026-04-30',
                'date_to' => '2026-05-01',
                'format' => VisitFormat::Office->value,
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('query.dateFrom', '2026-04-01')
                ->where('query.dateTo', '2026-04-30')
                ->where('availability.slots.0.startsAt', '2026-04-01T09:00:00+00:00')
                ->where('availability.slots.29.startsAt', '2026-04-30T09:00:00+00:00'));

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.create', [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-31',
                'format' => VisitFormat::Office->value,
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('query.dateFrom', '2026-05-01')
                ->where('query.dateTo', '2026-05-31')
                ->where('availability.slots.0.startsAt', '2026-05-01T09:00:00+00:00')
                ->where('availability.slots.30.startsAt', '2026-05-31T09:00:00+00:00'));
    }

    public function test_rescheduling_exposes_and_accepts_an_earlier_policy_valid_date_in_the_booking_month(): void
    {
        Carbon::setTestNow(CarbonImmutable::create(2026, 3, 1, 0, 0, 0, 'UTC'));
        [, $client, $specialist, $service] = $this->portalFixture();
        $booking = app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 3, 20, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'reschedule-month-range',
        );

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.show', $booking->getKey()).'?'.http_build_query([
                'reschedule' => 1,
                'date_from' => '2026-03-20',
                'date_to' => '2026-03-20',
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('availabilityRange.dateFrom', '2026-03-01')
                ->where('availabilityRange.dateTo', '2026-03-31')
                ->where('availability.slots.9.startsAt', '2026-03-10T09:00:00+00:00'));

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.reschedule', $booking->getKey()), [
                'starts_at' => '2026-03-10T09:00:00+00:00',
                'client_timezone' => 'UTC',
                'expected_event_version' => 1,
            ])
            ->assertRedirect(route('portal.bookings.show', $booking->getKey()));

        self::assertSame('2026-03-10T09:00:00+00:00', $booking->fresh()->startsAtUtc()->toIso8601String());
    }

    public function test_english_booking_validation_and_domain_failures_are_localized(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture(language: 'en');
        $consents = $this->acceptedConsents($organization);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), [])
            ->assertInvalid([
                'service_id' => 'Choose a service.',
                'specialist_id' => 'Choose a specialist.',
                'starts_at' => 'Choose a date and time.',
                'format' => 'Choose a visit format.',
            ]);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), $this->bookingPayload($service, $specialist, [
                'format' => VisitFormat::Online->value,
                'consents' => $consents,
            ]))
            ->assertInvalid(['format' => 'Choose another format for this service.']);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), $this->bookingPayload($service, $specialist, [
                'service_id' => 999999,
                'consents' => $consents,
            ]))
            ->assertInvalid(['service_id' => 'This service is not available.']);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), $this->bookingPayload($service, $specialist, [
                'specialist_id' => 999999,
                'consents' => $consents,
            ]))
            ->assertInvalid(['specialist_id' => 'There is no available specialist for this service right now.']);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), $this->bookingPayload($service, $specialist, [
                'starts_at' => '2026-03-16T11:00:00+00:00',
                'consents' => $consents,
            ]))
            ->assertInvalid(['starts_at' => 'This time is no longer available. Choose another.']);
    }

    public function test_english_booking_query_availability_and_timezone_failures_are_localized(): void
    {
        [, $client, $specialist, $service] = $this->portalFixture(language: 'en');

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.create', [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'date_from' => 'not-a-date',
                'format' => VisitFormat::Office->value,
            ]))
            ->assertInvalid(['date_from' => 'Choose a valid date.']);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.create', [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'format' => VisitFormat::Office->value,
                'display_timezone' => 'not/a-timezone',
            ]))
            ->assertInvalid(['display_timezone' => 'We could not determine your time zone. Refresh the page.']);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.availability', [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'date_from' => '2026-03-01',
                'date_to' => '2026-04-01',
                'format' => VisitFormat::Office->value,
            ]))
            ->assertInvalid(['date_to' => 'Choose one month at a time.']);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.availability', [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'date_from' => '2026-03-16',
                'date_to' => '2026-03-16',
                'format' => VisitFormat::Office->value,
                'display_timezone' => 'not/a-timezone',
            ]))
            ->assertInvalid(['display_timezone' => 'Choose a valid time zone.']);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.preferences.timezone'), ['timezone' => 'not/a-timezone'])
            ->assertInvalid(['timezone' => 'Choose a valid time zone.']);
    }

    public function test_english_reschedule_and_cancellation_failures_are_localized_and_russian_is_preserved(): void
    {
        [, $englishClient, $specialist, $service] = $this->portalFixture(language: 'en');
        $booking = app(CreateBooking::class)->handle(
            actor: $englishClient,
            client: $englishClient,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 3, 15, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'localized-actions',
        );

        $this->withSession(['client_portal.client_id' => $englishClient->getKey()])
            ->post(route('portal.bookings.reschedule', $booking->getKey()), [
                'starts_at' => '2026-03-17T09:00:00+00:00',
                'client_timezone' => 'UTC',
                'expected_event_version' => 99,
            ])
            ->assertInvalid(['expected_event_version' => 'This booking changed. Refresh the page and choose a time again.']);

        $this->withSession(['client_portal.client_id' => $englishClient->getKey()])
            ->post(route('portal.bookings.cancel', $booking->getKey()))
            ->assertInvalid(['booking' => 'This booking can no longer be cancelled online. Please contact us.']);

        $russianClient = Client::factory()->forOrganization($englishClient->organization)->create([
            'language' => 'ru',
            'timezone' => 'UTC',
        ]);

        $this->withSession(['client_portal.client_id' => $russianClient->getKey()])
            ->post(route('portal.bookings.store'), [])
            ->assertInvalid(['service_id' => 'Выберите услугу.']);
    }

    /** @return array{Organization, Client, Specialist, Service} */
    private function portalFixture(array $formats = ['office'], string $language = 'en'): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        foreach ([
            'offer' => 'offer_consent',
            'privacy' => 'privacy_consent',
            'medical_disclaimer' => 'medical_consent',
        ] as $documentType => $purpose) {
            app(PublishLegalDocument::class)->handle(
                app(CreatePlatformLegalDocumentDraft::class)->handle(
                    organization: $organization,
                    documentType: $documentType,
                    purpose: $purpose,
                    locale: 'en',
                    version: '2026-03-15-'.$documentType,
                    content: 'Synthetic legal fixture.',
                    isRequired: true,
                ),
            );
        }
        $client = Client::factory()->forOrganization($organization)->create([
            'language' => $language,
            'timezone' => 'UTC',
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'formats' => $formats,
        ]);
        SpecialistServiceAssignment::factory()
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();

        foreach (range(1, 7) as $weekday) {
            SpecialistWorkingHour::factory()->forSpecialist($specialist)->create([
                'weekday' => $weekday,
                'start_time' => '09:00',
                'end_time' => '10:00',
            ]);
        }

        return [$organization, $client, $specialist, $service];
    }

    /** @return array<string, int|string> */
    private function bookingPayload(Service $service, Specialist $specialist, array $overrides = []): array
    {
        return [
            'service_id' => $service->getKey(),
            'specialist_id' => $specialist->getKey(),
            'starts_at' => '2026-03-16T09:00:00+00:00',
            'format' => VisitFormat::Office->value,
            ...$overrides,
        ];
    }

    /** @return list<array{legal_document_id: int, granted: bool}> */
    private function acceptedConsents(Organization $organization): array
    {
        return LegalDocument::query()
            ->where('organization_id', $organization->getKey())
            ->orderBy('id')
            ->get()
            ->map(static fn (LegalDocument $document): array => [
                'legal_document_id' => $document->getKey(),
                'granted' => true,
            ])
            ->all();
    }
}
