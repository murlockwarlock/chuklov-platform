<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\Identity\Application\CreatePlatformLegalDocumentDraft;
use App\Modules\Identity\Application\PublishLegalDocument;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_booking_personal_data_is_persisted_atomically_and_prefilled_when_reopened(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $this->withSession(['client_portal.client_id' => $client->getKey()])->post(route('portal.bookings.store'), $this->bookingPayload($service, $specialist, [
            'personal_data' => ['first_name' => 'Новый', 'last_name' => 'Клиент', 'middle_name' => 'Тестовый',
                'gender' => 'MALE', 'birth_date' => '1990-01-02', 'country' => 'Казахстан',
                'city' => 'Алматы', 'phone' => '+7 (701) 234-56-78', 'email' => $client->email],
            'consents' => $this->acceptedConsents($organization),
        ]))->assertSessionHasNoErrors()->assertRedirect();
        self::assertSame('Клиент Новый Тестовый', $client->refresh()->full_name);
        self::assertSame('+77012345678', $client->phone);
        self::assertSame(1, Booking::query()->where('client_id', $client->getKey())->count());
        $this->get(route('portal.bookings.create'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('profile.firstName', 'Новый')->where('profile.city', 'Алматы')->where('profile.phone', '+77012345678'));
        $this->get(route('portal.profile'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('profile.lastName', 'Клиент')->where('profile.middleName', 'Тестовый'));
    }

    /** @return array<string, array{array<string, list<string>>, int}> */
    public static function personalDataBookingModes(): array
    {
        return [
            'single' => [[], 1],
            'multiple' => [['additional_starts_at' => ['2026-03-17T09:00:00+00:00']], 2],
        ];
    }

    /** @param array<string, list<string>> $additional */
    #[DataProvider('personalDataBookingModes')]
    public function test_booking_personal_data_replay_does_not_overwrite_profile_or_accept_changed_intent(array $additional, int $bookingCount): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $payload = $this->bookingPayload($service, $specialist, [
            'personal_data' => ['first_name' => 'Original booking name'],
            'idempotency_key' => '00000000-0000-4000-8000-000000000123',
            'consents' => $this->acceptedConsents($organization),
            ...$additional,
        ]);
        $this->withSession(['client_portal.client_id' => $client->getKey()]);
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasNoErrors();
        $auditCount = AuditEvent::query()->count();
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasNoErrors();
        self::assertSame($auditCount, AuditEvent::query()->count());
        self::assertSame($bookingCount, Booking::query()->where('client_id', $client->getKey())->count());
        $payload['personal_data']['first_name'] = 'Changed replay';
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasErrors();
        self::assertSame('Original booking name', $client->refresh()->first_name);
        self::assertSame($bookingCount, Booking::query()->where('client_id', $client->getKey())->count());
    }

    public function test_missing_or_invalid_booking_personal_data_cannot_create_a_booking_or_mutate_identity(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $original = $client->only(['first_name', 'last_name', 'city', 'phone']);
        foreach ([['first_name' => ''], ['birth_date' => '2999-01-01'], ['phone' => '+7 garbage'], ['gender' => 'OTHER']] as $invalid) {
            $this->withSession(['client_portal.client_id' => $client->getKey()])->post(route('portal.bookings.store'), $this->bookingPayload($service, $specialist, [
                'personal_data' => [...$invalid, 'city' => 'Must not be saved'], 'consents' => $this->acceptedConsents($organization),
            ]))->assertSessionHasErrors('personal_data.'.array_key_first($invalid));
            self::assertSame($original, $client->refresh()->only(array_keys($original)));
            self::assertSame(0, Booking::query()->where('client_id', $client->getKey())->count());
        }
    }

    public function test_later_booking_failure_rolls_back_personal_data_and_its_audit(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $original = $client->only(['first_name', 'last_name', 'city', 'phone']);
        $auditCount = AuditEvent::query()->where('target_type', Client::class)
            ->where('target_id', (string) $client->getKey())->count();
        $this->withSession(['client_portal.client_id' => $client->getKey()])->post(route('portal.bookings.store'), $this->bookingPayload($service, $specialist, [
            'personal_data' => ['first_name' => 'Should roll back', 'city' => 'Changed city'],
            'consents' => [],
        ]))->assertSessionHasErrors('consents');
        self::assertSame($original, $client->refresh()->only(array_keys($original)));
        self::assertSame(0, Booking::query()->where('client_id', $client->getKey())->count());
        self::assertSame($auditCount, AuditEvent::query()->where('target_type', Client::class)
            ->where('target_id', (string) $client->getKey())->count());
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

    public function test_booking_confirmation_survives_a_repeated_create_page_request(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $documents = $this->acceptedConsents($organization);

        $response = $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'starts_at' => '2026-03-16T09:00:00+00:00',
                'format' => VisitFormat::Office->value,
                'consents' => $documents,
            ])
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');

        $this->get($location)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/BookingCreate')
                ->where('bookingResult.bookingId', Booking::query()->sole()->getKey()));

        $this->get($location)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/BookingCreate')
                ->where('bookingResult.bookingId', Booking::query()->sole()->getKey()));
    }

    public function test_portal_ordinary_booking_preserves_party_size(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $consents = $this->acceptedConsents($organization);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'starts_at' => '2026-03-16T09:00:00+00:00',
                'format' => VisitFormat::Office->value,
                'party_size' => 3,
                'consents' => $consents,
            ])
            ->assertRedirect();

        $booking = Booking::query()->sole();
        self::assertSame(3, $booking->party_size);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.show', $booking->getKey()))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('booking.partySize', 3));
    }

    public function test_cancelled_booking_is_not_replayed_as_a_new_portal_booking(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $consents = $this->acceptedConsents($organization);
        $payload = [
            'service_id' => $service->getKey(),
            'specialist_id' => $specialist->getKey(),
            'starts_at' => '2026-03-20T09:00:00+00:00',
            'format' => VisitFormat::Office->value,
            'consents' => $consents,
        ];

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), $payload)
            ->assertRedirect();
        $firstBooking = Booking::query()->sole();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.cancel', $firstBooking->getKey()))
            ->assertRedirect();
        self::assertSame(BookingStatus::Cancelled, $firstBooking->refresh()->status);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.bookings.store'), $payload)
            ->assertRedirect();

        self::assertSame(2, Booking::query()->count());
        self::assertSame(BookingStatus::Cancelled, $firstBooking->refresh()->status);
        $secondBooking = Booking::query()->where('id', '<>', $firstBooking->getKey())->sole();
        self::assertSame(BookingStatus::Requested, $secondBooking->status);
    }

    public function test_booking_exposes_each_required_published_document_for_the_compact_consent_flow(): void
    {
        [, $client, $specialist, $service] = $this->portalFixture(language: 'en');

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.create', [
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'format' => VisitFormat::Office->value,
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/BookingCreate')
                ->has('legalDocuments', 3)
                ->where('legalDocuments.0.documentType', 'offer')
                ->where('legalDocuments.1.documentType', 'privacy')
                ->where('legalDocuments.2.documentType', 'medical_disclaimer')
                ->where('legalDocuments.0.title', 'Offer')
                ->where('legalDocuments.1.title', 'Privacy policy')
                ->where('legalDocuments.2.title', 'Medical disclaimer')
                ->where('legalDocuments.0.isRequired', true)
                ->where('legalDocuments.1.isRequired', true)
                ->where('legalDocuments.2.isRequired', true));
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

    public function test_portal_multi_session_create_replay_receipt_and_independent_cancellation(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $service->update(['requires_medical_profile' => false]);
        $payload = $this->bookingPayload($service, $specialist, [
            'additional_starts_at' => ['2026-03-17T09:00:00+00:00'],
            'idempotency_key' => 'f578ef96-74f4-4a1b-b73f-5e641b851a20',
            'consents' => $this->acceptedConsents($organization),
        ]);
        $this->withSession(['client_portal.client_id' => $client->getKey()]);
        $response = $this->post(route('portal.bookings.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $receiptUrl = $response->headers->get('Location');
        self::assertCount(2, Booking::query()->where('client_id', $client->id)->get());
        self::assertSame(2, DB::table('scenario_events')->where('event_name', 'booking.created')->count());
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasNoErrors();
        self::assertSame(2, Booking::query()->count());
        self::assertSame(2, DB::table('scenario_events')->where('event_name', 'booking.created')->count());
        $this->get($receiptUrl)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bookingResult.startsAtList', ['2026-03-16T09:00:00+00:00', '2026-03-17T09:00:00+00:00'])
            ->where('bookingResult.medicalProfileUrl', null));
        $admin = User::factory()->forOrganization($organization)->create();
        foreach (Booking::query()->get() as $booking) {
            self::assertSame(BookingStatus::Requested, $booking->status);
            app(ConfirmBooking::class)->handle($admin, $booking);
        }
        $first = Booking::query()->orderBy('starts_at')->firstOrFail();
        $this->post(route('portal.bookings.cancel', $first->id))->assertSessionHasNoErrors();
        self::assertSame(BookingStatus::Cancelled, $first->refresh()->status);
        self::assertSame(BookingStatus::Confirmed, Booking::query()->whereKeyNot($first->id)->sole()->status);
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasNoErrors();
        self::assertSame(2, Booking::query()->count());
        self::assertSame(BookingStatus::Cancelled, $first->refresh()->status);
        $payload['additional_starts_at'] = ['2026-03-18T09:00:00+00:00'];
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasErrors('idempotency_key');
        self::assertSame(2, Booking::query()->count());
        $payload['starts_at'] = '2026-03-19T09:00:00+00:00';
        $payload['additional_starts_at'] = ['2026-03-20T09:00:00+00:00'];
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasErrors('idempotency_key');
        self::assertSame(2, Booking::query()->count());
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $otherClient->id]);
        $this->get($receiptUrl)->assertNotFound();
        $foreignClient = Client::factory()->forOrganization(Organization::factory()->create())->create();
        $this->withSession(['client_portal.client_id' => $foreignClient->id]);
        $this->post(route('portal.bookings.store'), $payload)->assertUnauthorized();
    }

    public function test_multi_session_conflict_rolls_back_bookings_consents_intents_and_events(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $payload = $this->bookingPayload($service, $specialist, [
            'additional_starts_at' => ['2026-03-17T11:00:00+00:00'],
            'idempotency_key' => 'f578ef96-74f4-4a1b-b73f-5e641b851a21',
            'consents' => $this->acceptedConsents($organization),
        ]);
        $this->withSession(['client_portal.client_id' => $client->id]);
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasErrors('additional_starts_at');
        foreach (['bookings', 'client_consents', 'booking_idempotency_keys', 'scenario_events'] as $table) {
            self::assertSame(0, DB::table($table)->count(), $table);
        }
        $payload['additional_starts_at'] = [$payload['starts_at']];
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasErrors('additional_starts_at');
        self::assertSame(0, Booking::query()->count());
        unset($payload['idempotency_key']);
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasErrors('idempotency_key');
        $payload['idempotency_key'] = 'f578ef96-74f4-4a1b-b73f-5e641b851a21';
        $payload['client_timezone'] = 'Not/Timezone';
        $this->post(route('portal.bookings.store'), $payload)->assertSessionHasErrors('client_timezone');
        $this->get(route('portal.bookings.create', ['booking_ids' => [999999]]))->assertNotFound();
    }

    public function test_medical_continuation_returns_a_batch_to_all_bookings_and_rejects_external_return_targets(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $this->withSession(['client_portal.client_id' => $client->id]);
        $this->post(route('portal.bookings.store'), $this->bookingPayload($service, $specialist, [
            'additional_starts_at' => ['2026-03-17T09:00:00+00:00'],
            'idempotency_key' => 'f578ef96-74f4-4a1b-b73f-5e641b851a22',
            'consents' => $this->acceptedConsents($organization),
        ]))->assertSessionHasNoErrors();
        $booking = Booking::query()->orderBy('starts_at')->firstOrFail();
        $query = ['booking_id' => $booking->id, 'return_to' => 'bookings', 'edit' => 1];
        $page = $this->get(route('portal.medical-profile', $query))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('returnUrl', route('portal.bookings.index'))->where('returnLabelKey', 'bookings.title'));
        $snapshot = $page->viewData('page')['props']['profile']['expectedSnapshot'];
        $this->post(route('portal.medical-profile.update', $query), [
            'expected_snapshot' => $snapshot, 'complaints' => 'Synthetic symptoms', 'goals' => 'Synthetic goals',
            'answers' => ['symptoms' => ['neck'], 'pain_vas' => null, 'no_chronic_conditions' => true, 'had_operations' => false, 'had_injuries' => false],
        ])->assertSessionHasNoErrors()->assertRedirect(route('portal.bookings.index'));
        $this->get(route('portal.medical-profile', ['booking_id' => $booking->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('returnUrl', route('portal.bookings.show', $booking->id))->where('returnLabelKey', 'medical.backToBooking'));
        $this->get(route('portal.medical-profile', ['booking_id' => $booking->id, 'return_to' => 'https://example.test']))
            ->assertSessionHasErrors('return_to');
        self::assertSame(2, Booking::query()->count());
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
            'first_name' => 'QA', 'last_name' => 'Booking', 'gender' => 'MALE',
            'birth_date' => '1990-01-02', 'country' => 'United States', 'city' => 'Washington', 'phone' => '+12025550123',
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
