<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Channels\Application\HandleTelegramBookingConfirmation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Application\ScenarioContextFactory;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;
use App\Modules\Scheduling\Application\ApproveHomeVisitBooking;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\BookingDateTimeFormatter;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\CreateWorkingLocation;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Application\SaveLocationDay;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Application\UpdateSpecialistViewerTimezone;
use App\Modules\Scheduling\Application\UpdateWorkingLocation;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use App\Modules\Scheduling\Domain\Services\SlotCalculator;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class PhaseOneBookingLocationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 9, 1, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_viewer_and_client_formatters_keep_utc_invariant_across_berlin_dst(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $client->forceFill(['timezone' => 'Asia/Almaty'])->save();
        $specialist->forceFill([
            'viewer_timezone' => 'Europe/Berlin',
            'viewer_timezone_source' => 'manual',
        ])->save();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Confirmed,
                'visit_format' => VisitFormat::Online,
                'starts_at' => CarbonImmutable::create(2026, 9, 4, 4, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 4, 5, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 4, 5, 0, 0, 'UTC'),
                'schedule_timezone' => 'Asia/Dubai',
                'client_timezone' => 'Asia/Almaty',
            ]);

        app(OrganizationContext::class)->set($organization);
        $before = $booking->startsAtUtc()->toIso8601String();
        $formatter = app(BookingDateTimeFormatter::class);

        self::assertSame(['date' => '04-09-2026', 'time' => '09:00', 'timezone' => 'Asia/Almaty'], $formatter->forClient($booking));
        self::assertSame(['date' => '04-09-2026', 'time' => '06:00', 'timezone' => 'Europe/Berlin'], $formatter->forSpecialist($booking));
        self::assertSame($before, $booking->refresh()->startsAtUtc()->toIso8601String());

        $calculator = app(SlotCalculator::class);
        $springSlots = $calculator->calculate(
            date: LocalDate::from('2026-03-29'),
            scheduleTimezone: 'Europe/Berlin',
            workingIntervals: [WallClockInterval::from('01:00', '04:00')],
            customIntervals: [],
            dayOff: false,
            unavailableIntervals: [],
            bookingIntervals: [],
            durationMinutes: 60,
            bufferMinutes: 0,
            leadTimeMinutes: 0,
            now: CarbonImmutable::create(2026, 3, 28, 12, 0, 0, 'UTC'),
            format: VisitFormat::Online,
            displayTimezone: 'Europe/Berlin',
        );
        self::assertSame([
            '2026-03-29T00:00:00+00:00',
            '2026-03-29T01:00:00+00:00',
        ], array_map(static fn ($slot): string => $slot->startsAt->toIso8601String(), $springSlots));

        $autumnSlots = $calculator->calculate(
            date: LocalDate::from('2026-10-25'),
            scheduleTimezone: 'Europe/Berlin',
            workingIntervals: [WallClockInterval::from('02:00', '04:00')],
            customIntervals: [],
            dayOff: false,
            unavailableIntervals: [],
            bookingIntervals: [],
            durationMinutes: 60,
            bufferMinutes: 0,
            leadTimeMinutes: 0,
            now: CarbonImmutable::create(2026, 10, 24, 12, 0, 0, 'UTC'),
            format: VisitFormat::Online,
            displayTimezone: 'Europe/Berlin',
        );
        self::assertSame([
            '2026-10-25T00:00:00+00:00',
            '2026-10-25T01:00:00+00:00',
        ], array_map(static fn ($slot): string => $slot->startsAt->toIso8601String(), array_slice($autumnSlots, 0, 2)));
        self::assertNotSame($autumnSlots[0]->startsAt->toIso8601String(), $autumnSlots[1]->startsAt->toIso8601String());
    }

    public function test_specialist_device_timezone_is_only_a_suggestion_and_manual_choice_survives_it(): void
    {
        [$organization, $admin, , $specialist] = $this->fixture();
        $context = app(OrganizationContext::class);
        $context->set($organization);
        $timezone = app(UpdateSpecialistViewerTimezone::class);

        self::assertSame('Asia/Almaty', app(ResolveSpecialistViewerTimezone::class)->forSpecialist($specialist));

        $suggested = $timezone->suggest($admin, $specialist, 'Europe/Berlin');
        self::assertSame('Asia/Almaty', app(ResolveSpecialistViewerTimezone::class)->forSpecialist($suggested));
        self::assertSame('Europe/Berlin', $suggested->viewer_timezone_suggestion);

        $deviceChoice = $timezone->handle($admin, $specialist, 'Europe/Berlin', 'device');
        self::assertSame('Europe/Berlin', $deviceChoice->viewer_timezone);
        self::assertSame('device', $deviceChoice->viewer_timezone_source);

        $manualChoice = $timezone->handle($admin, $specialist, 'Asia/Dubai', 'manual');
        $laterSuggestion = $timezone->suggest($admin, $manualChoice, 'Europe/Berlin');

        self::assertSame('Asia/Dubai', $laterSuggestion->viewer_timezone);
        self::assertSame('manual', $laterSuggestion->viewer_timezone_source);
        self::assertNull($laterSuggestion->viewer_timezone_suggestion);
    }

    public function test_working_location_snapshot_is_scoped_and_survives_location_changes(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]]);
        $location = app(CreateWorkingLocation::class)->handle(
            actor: $admin,
            name: 'Berlin Mitte',
            address: 'Alexanderplatz 1',
            timezone: 'Europe/Berlin',
            isDefaultOffice: false,
        );
        $secondLocation = app(CreateWorkingLocation::class)->handle(
            actor: $admin,
            name: 'Кабинет Алматы',
            address: 'Абая 10',
            timezone: 'Asia/Almaty',
            isDefaultOffice: true,
        );
        self::assertFalse($location->refresh()->is_default_office);
        self::assertTrue($secondLocation->refresh()->is_default_office);

        $booking = app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 9, 7, 7, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            clientTimezone: 'Asia/Almaty',
            idempotencyKey: 'phase-one-office-location',
            workingLocationId: $location->getKey(),
        );

        self::assertSame($location->getKey(), $booking->working_location_id);
        self::assertSame('Alexanderplatz 1', $booking->location);
        self::assertSame([
            'type' => 'office',
            'name' => 'Berlin Mitte',
            'address' => 'Alexanderplatz 1',
            'timezone' => 'Europe/Berlin',
            'latitude' => null,
            'longitude' => null,
            'map_url' => null,
        ], $booking->locationSnapshot());

        $updated = app(UpdateWorkingLocation::class)->handle(
            actor: $admin,
            location: $location,
            name: 'Berlin Mitte neu',
            address: 'Neue Adresse 2',
            timezone: 'Europe/Berlin',
            isActive: false,
        );

        self::assertFalse($updated->is_active);
        self::assertSame('Berlin Mitte', $booking->refresh()->locationSnapshot()['name']);
        self::assertSame('Alexanderplatz 1', $booking->locationSnapshot()['address']);
        self::assertTrue(app(CalculateAvailability::class)->isExistingBookingAligned($booking));

        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherLocation = WorkingLocation::factory()->forOrganization($otherOrganization)->create();
        $this->expectException(AuthorizationException::class);
        app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 9, 14, 7, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            clientTimezone: 'Asia/Almaty',
            idempotencyKey: 'phase-one-cross-tenant-location',
            workingLocationId: $otherLocation->getKey(),
        );
    }

    public function test_legacy_office_address_backfill_is_idempotent(): void
    {
        [$organization, $admin] = $this->fixture();
        app(SetOrganizationSetting::class)->handle($admin, OrganizationSettingKey::OfficeLocation, 'Старый адрес, кабинет 3');
        $migration = require database_path('migrations/2026_09_03_205012_backfill_working_locations_and_seed_home_visit_buffer.php');
        $migration->up();
        $migration->up();

        $locations = WorkingLocation::query()->where('organization_id', $organization->getKey())->get();
        self::assertCount(1, $locations);
        self::assertSame('Старый адрес, кабинет 3', $locations->sole()->address);
        self::assertTrue($locations->sole()->is_default_office);
        self::assertSame($organization->defaultTimezone(), $locations->sole()->timezone);
    }

    public function test_home_visit_snapshot_location_day_and_full_occupied_cycle_are_authoritative(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $service->forceFill(['duration_minutes' => 120, 'buffer_minutes' => 0, 'formats' => ['home']])->save();
        app(SetOrganizationSetting::class)->handle($admin, OrganizationSettingKey::HomeVisitOccupiedBufferMinutes, 150);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 5,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]]);
        app(SaveLocationDay::class)->handle(
            actor: $admin,
            locationDay: null,
            areaName: 'Bang Tao',
            weekday: 5,
            specificDate: null,
            startTime: '10:00',
            endTime: '18:00',
            timezone: 'Asia/Bangkok',
            isActive: true,
            notes: null,
        );

        $availability = app(CalculateAvailability::class)->forStaff(
            actor: $admin,
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            dateFrom: '2026-09-04',
            dateTo: '2026-09-04',
            format: VisitFormat::HomeVisit,
            displayTimezone: 'Europe/Berlin',
            locationArea: 'Bang Tao',
        );
        self::assertSame('Asia/Bangkok', $availability->scheduleTimezone);
        self::assertSame(['2026-09-04T03:00:00+00:00'], array_map(static fn ($slot): string => $slot->startsAt->toIso8601String(), $availability->slots));

        $booking = app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 9, 4, 3, 0, 0, 'UTC'),
            format: VisitFormat::HomeVisit,
            clientTimezone: 'Europe/Berlin',
            idempotencyKey: 'phase-one-home-visit',
            location: '123 Moo 5, Bang Tao',
            locationArea: 'Bang Tao',
            latitude: 7.98,
            longitude: 98.3,
            mapUrl: 'https://maps.example.test/bang-tao',
        );

        self::assertSame(BookingStatus::PendingReview, $booking->status);
        self::assertSame('Bang Tao', $booking->location_area);
        self::assertSame('123 Moo 5, Bang Tao', $booking->locationSnapshot()['address']);
        self::assertSame('Asia/Bangkok', $booking->locationSnapshot()['timezone']);
        self::assertSame('2026-09-04T07:30:00+00:00', $booking->blockingEndsAtUtc()->toIso8601String());

        $confirmed = app(ApproveHomeVisitBooking::class)->handle($admin, $booking);
        self::assertSame(BookingStatus::Confirmed, $confirmed->status);

        $sameDay = app(CalculateAvailability::class)->forStaff(
            actor: $admin,
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            dateFrom: '2026-09-04',
            dateTo: '2026-09-04',
            format: VisitFormat::HomeVisit,
            locationArea: 'Bang Tao',
        );
        self::assertCount(0, $sameDay->slots);

        $otherDay = app(CalculateAvailability::class)->forStaff(
            actor: $admin,
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            dateFrom: '2026-09-11',
            dateTo: '2026-09-11',
            format: VisitFormat::HomeVisit,
            locationArea: 'Bang Tao',
        );
        self::assertCount(1, $otherDay->slots);

        $this->expectException(ValidationException::class);
        app(CalculateAvailability::class)->forStaff(
            actor: $admin,
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            dateFrom: '2026-09-04',
            dateTo: '2026-09-04',
            format: VisitFormat::HomeVisit,
            locationArea: 'Patong',
        );
    }

    public function test_new_home_visit_requires_a_destination_address(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        app(OrganizationContext::class)->set($organization);

        try {
            app(CreateBooking::class)->handle(
                actor: $admin,
                client: $client,
                specialist: $specialist,
                service: $service,
                startsAt: CarbonImmutable::create(2026, 9, 4, 4, 0, 0, 'UTC'),
                format: VisitFormat::HomeVisit,
                clientTimezone: 'Asia/Almaty',
                idempotencyKey: 'phase-one-home-visit-without-address',
            );
            self::fail('A HomeVisit without a destination address must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('location', $exception->errors());
        }
    }

    public function test_scenario_context_uses_viewer_timezone_for_specialist_and_client_timezone_for_client(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $client->forceFill(['timezone' => 'Asia/Almaty'])->save();
        $specialist->forceFill([
            'viewer_timezone' => 'Europe/Berlin',
            'viewer_timezone_source' => 'manual',
        ])->save();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Requested,
                'visit_format' => VisitFormat::Office,
                'starts_at' => CarbonImmutable::create(2026, 9, 4, 4, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 4, 5, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 4, 5, 0, 0, 'UTC'),
                'schedule_timezone' => 'Europe/Berlin',
                'client_timezone' => 'Asia/Almaty',
                'location_snapshot' => [
                    'type' => 'office',
                    'name' => 'Berlin Mitte',
                    'address' => 'Alexanderplatz 1',
                    'timezone' => 'Europe/Berlin',
                ],
            ]);
        $event = app(RecordScenarioEvent::class)->bookingCreated(
            booking: $booking,
            causationId: 'phase-one-context',
            occurredAt: CarbonImmutable::now(),
        );
        $factory = app(ScenarioContextFactory::class);
        $context = $factory->evaluationContext($event);

        $internal = $factory->renderContext(
            context: $context,
            recipient: new ScenarioRecipient('internal', $client->getKey(), $admin->getKey(), 'ru'),
        );
        $clientContext = $factory->renderContext(
            context: $context,
            recipient: new ScenarioRecipient('client', $client->getKey(), null, 'ru'),
        );

        self::assertSame('06:00', $internal['booking']['local_time']);
        self::assertSame('Europe/Berlin', $internal['booking']['timezone']);
        self::assertSame('09:00', $clientContext['booking']['local_time']);
        self::assertSame('Asia/Almaty', $clientContext['booking']['timezone']);
        self::assertSame("Berlin Mitte\nAlexanderplatz 1", $internal['booking']['location_label']);
        self::assertSame(url('/admin/bookings/'.$booking->getKey()), $internal['booking']['crm_url']);
    }

    public function test_telegram_booking_confirmation_is_scoped_authoritative_and_idempotent(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $specialist->forceFill(['staff_user_id' => $admin->getKey()])->save();
        OrganizationChannelIdentity::factory()->forUser($admin)->verified()->create([
            'external_id' => '424242',
        ]);
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Requested,
                'visit_format' => VisitFormat::Office,
                'starts_at' => CarbonImmutable::create(2026, 9, 7, 7, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 7, 8, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 7, 8, 0, 0, 'UTC'),
                'schedule_timezone' => 'Europe/Berlin',
                'client_timezone' => 'Asia/Almaty',
            ]);

        config()->set('nutgram.token', FakeNutgram::TOKEN);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        $bot = FakeNutgram::instance();
        $bot->setCommonUser(TelegramUser::make(
            id: 424242,
            is_bot: false,
            first_name: 'Specialist',
            language_code: 'ru',
        ));
        $handler = app(HandleTelegramBookingConfirmation::class);
        $bot->onCallbackQueryData('booking:confirm:\d+', function (Nutgram $bot) use ($handler): void {
            $handler->handle($bot);
        });

        $bot->hearCallbackQueryData('booking:confirm:'.$booking->getKey())->reply();
        $bot->assertReply('answerCallbackQuery', ['text' => '✅ Запись подтверждена'], 0);
        $bot->hearCallbackQueryData('booking:confirm:'.$booking->getKey())->reply();
        $bot->assertReply('answerCallbackQuery', ['text' => 'Запись уже подтверждена.'], 0);

        self::assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        self::assertSame(1, BookingEvent::query()->where('booking_id', $booking->getKey())->count());
        self::assertSame(1, DB::table('audit_events')->where('action', 'booking.confirmed')->count());

        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();
        $otherService = Service::factory()->forOrganization($otherOrganization)->create();
        $otherBooking = Booking::factory()
            ->forOrganization($otherOrganization)
            ->forClient($otherClient)
            ->forSpecialist($otherSpecialist)
            ->forService($otherService)
            ->create(['status' => BookingStatus::Requested]);

        $bot->hearCallbackQueryData('booking:confirm:'.$otherBooking->getKey())->reply();
        $bot->assertReply('answerCallbackQuery', ['text' => 'Действие недоступно. Откройте CRM.'], 0);
        self::assertSame(BookingStatus::Requested, $otherBooking->refresh()->status);
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'Asia/Almaty']);
        $specialist = Specialist::factory()->forOrganization($organization)->create([
            'timezone' => 'Asia/Dubai',
            'staff_user_id' => $admin->getKey(),
        ]);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'formats' => ['office', 'home', 'online'],
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);

        return [$organization, $admin, $client, $specialist, $service];
    }
}
