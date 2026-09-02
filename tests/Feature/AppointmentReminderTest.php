<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\AppointmentReminderScheduler;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Application\UpdateAppointmentReminders;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Models\AppointmentReminder;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scheduling\Application\CancelBooking;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class AppointmentReminderTest extends TestCase
{
    use RefreshDatabase;

    private RecordingNotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 9, 2, 10, 0, 0, 'UTC'));
        $this->channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$this->channel]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_confirmation_schedules_client_and_specialist_reminders_from_booking_start(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking(
            $organization,
            $client,
            $specialist,
            $service,
            VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'),
            'ул. Абая, 10',
            BookingStatus::Requested,
        );

        app(ConfirmBooking::class)->handle($admin, $booking);

        $actions = ScenarioAction::query()
            ->where('organization_id', $organization->getKey())
            ->where('kind', 'appointment_reminder')
            ->with('appointmentReminder')
            ->get();

        self::assertCount(4, $actions);
        self::assertSame(
            [
                '1|days' => '2026-09-04 14:00:00',
                '2|hours' => '2026-09-05 12:00:00',
                '30|minutes' => '2026-09-05 13:30:00',
            ],
            $actions
                ->where('recipient_type', 'client')
                ->mapWithKeys(fn (ScenarioAction $action): array => [
                    $action->appointmentReminder->offset_value.'|'.$action->appointmentReminder->offset_unit->value => $action->scheduled_for->utc()->format('Y-m-d H:i:s'),
                ])
                ->all(),
        );
        self::assertSame(
            '2026-09-05 13:30:00',
            $actions->firstWhere('recipient_type', 'internal')->scheduled_for->utc()->format('Y-m-d H:i:s'),
        );
        self::assertSame(ScenarioActionStatus::Scheduled, $actions->first()->status);
    }

    public function test_expired_offsets_are_not_scheduled_as_catch_up_messages_and_retries_are_idempotent(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking(
            $organization,
            $client,
            $specialist,
            $service,
            VisitFormat::Office,
            CarbonImmutable::now()->addHour(),
            'ул. Абая, 10',
        );
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'confirmed-test', CarbonImmutable::now());

        app(AppointmentReminderScheduler::class)->schedule($booking, $event);
        app(AppointmentReminderScheduler::class)->schedule($booking, $event);

        $actions = ScenarioAction::query()
            ->where('booking_id', $booking->getKey())
            ->where('kind', 'appointment_reminder')
            ->with('appointmentReminder', 'deliveries')
            ->get();

        self::assertCount(2, $actions);
        self::assertEqualsCanonicalizing([30, 30], $actions->pluck('appointmentReminder.offset_value')->all());
        self::assertSame(2, $actions->pluck('appointment_reminder_id')->unique()->count());
        self::assertSame(2, $actions->flatMap(fn (ScenarioAction $action) => $action->deliveries)->count());
    }

    public function test_rescheduling_cancels_old_future_reminders_and_creates_new_offsets(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking(
            $organization,
            $client,
            $specialist,
            $service,
            VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'),
            'ул. Абая, 10',
        );
        $firstEvent = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'confirmed-test', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($booking, $firstEvent);

        $booking->forceFill([
            'starts_at' => CarbonImmutable::create(2026, 9, 7, 16, 0, 0, 'UTC'),
            'ends_at' => CarbonImmutable::create(2026, 9, 7, 17, 0, 0, 'UTC'),
            'blocking_ends_at' => CarbonImmutable::create(2026, 9, 7, 17, 0, 0, 'UTC'),
            'event_version' => 2,
        ])->save();
        $secondEvent = app(RecordScenarioEvent::class)->bookingRescheduled($booking, 'rescheduled-test', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($booking, $secondEvent);

        self::assertSame(4, ScenarioAction::query()->where('booking_id', $booking->getKey())->where('status', ScenarioActionStatus::Cancelled->value)->count());
        self::assertSame(4, ScenarioAction::query()->where('booking_id', $booking->getKey())->where('status', ScenarioActionStatus::Scheduled->value)->count());
        self::assertSame(4, ScenarioAction::query()->where('booking_id', $booking->getKey())->where('status', ScenarioActionStatus::Cancelled->value)->whereHas('deliveries', fn ($query) => $query->where('status', ScenarioDeliveryStatus::Suppressed->value))->count());
        self::assertSame(
            '2026-09-06 16:00:00',
            ScenarioAction::query()
                ->where('booking_id', $booking->getKey())
                ->where('status', ScenarioActionStatus::Scheduled->value)
                ->whereHas('appointmentReminder', fn ($query) => $query->where('offset_value', 1)->where('offset_unit', ScenarioDelayUnit::Days->value))
                ->sole()
                ->scheduled_for
                ->utc()
                ->format('Y-m-d H:i:s'),
        );
    }

    public function test_cancelling_booking_suppresses_all_future_reminders(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking(
            $organization,
            $client,
            $specialist,
            $service,
            VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'),
            'ул. Абая, 10',
        );
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'confirmed-test', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($booking, $event);

        app(CancelBooking::class)->handle($admin, $booking);

        self::assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        self::assertSame(4, ScenarioAction::query()->where('booking_id', $booking->getKey())->where('status', ScenarioActionStatus::Cancelled->value)->count());
        self::assertSame(4, ScenarioAction::query()->where('booking_id', $booking->getKey())->whereHas('deliveries', fn ($query) => $query->where('status', ScenarioDeliveryStatus::Suppressed->value))->count());
    }

    public function test_online_client_reminder_uses_ready_zoom_url_as_the_action_button(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking(
            $organization,
            $client,
            $specialist,
            $service,
            VisitFormat::Online,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'),
            null,
            BookingStatus::Confirmed,
            MeetingLinkMode::Manual,
            'https://zoom.us/j/aikhana',
        );
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'confirmed-test', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($booking, $event);
        $action = ScenarioAction::query()
            ->where('booking_id', $booking->getKey())
            ->where('recipient_type', 'client')
            ->whereHas('appointmentReminder', fn ($query) => $query->where('offset_value', 30)->where('offset_unit', ScenarioDelayUnit::Minutes->value))
            ->with('deliveries')
            ->sole();
        $this->makeDue($action);

        app(ExecuteScenarioAction::class)->handle($action->getKey());

        $message = $this->channel->messages[0] ?? null;
        self::assertNotNull($message);
        self::assertStringContainsString('онлайн-запись', $message->body);
        self::assertSame('Подключиться к Zoom', $message->actionButton?->text);
        self::assertSame('https://zoom.us/j/aikhana', $message->actionButton?->url);
    }

    public function test_office_reminder_uses_the_booking_address_override(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking(
            $organization,
            $client,
            $specialist,
            $service,
            VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'),
            'ул. Достык, 42, кабинет 3',
        );
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'confirmed-test', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($booking, $event);
        $action = ScenarioAction::query()
            ->where('booking_id', $booking->getKey())
            ->where('recipient_type', 'client')
            ->whereHas('appointmentReminder', fn ($query) => $query->where('offset_value', 2)->where('offset_unit', ScenarioDelayUnit::Hours->value))
            ->with('deliveries')
            ->sole();
        $this->makeDue($action);

        app(ExecuteScenarioAction::class)->handle($action->getKey());

        self::assertStringContainsString('ул. Достык, 42, кабинет 3', $this->channel->messages[0]->body);
    }

    public function test_operator_can_add_disable_and_remove_reminder_offsets(): void
    {
        [$organization, $admin] = $this->fixture();

        app(UpdateAppointmentReminders::class)->handle($admin, [
            'client_reminders' => [
                ['offset_value' => 1, 'offset_unit' => 'days', 'is_enabled' => true],
                ['offset_value' => 30, 'offset_unit' => 'minutes', 'is_enabled' => false],
            ],
            'specialist_reminders' => [],
        ]);

        self::assertSame(
            [
                '1|days' => true,
                '30|minutes' => false,
            ],
            AppointmentReminder::query()
                ->where('organization_id', $organization->getKey())
                ->where('recipient_type', 'client')
                ->orderBy('offset_value')
                ->get()
                ->mapWithKeys(fn (AppointmentReminder $reminder): array => [
                    $reminder->offset_value.'|'.$reminder->offset_unit->value => $reminder->is_enabled,
                ])
                ->all(),
        );

        app(UpdateAppointmentReminders::class)->handle($admin, [
            'client_reminders' => [
                ['offset_value' => 2, 'offset_unit' => 'hours', 'is_enabled' => true],
            ],
            'specialist_reminders' => [],
        ]);

        self::assertSame(0, AppointmentReminder::query()->where('organization_id', $organization->getKey())->where('recipient_type', 'client')->where('is_enabled', true)->whereIn('offset_value', [1, 30])->count());
        self::assertTrue(AppointmentReminder::query()->where('organization_id', $organization->getKey())->where('recipient_type', 'client')->where('offset_value', 2)->where('offset_unit', 'hours')->value('is_enabled'));
    }

    /** @return array{Organization, User, Client, Specialist, Service} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Aikhana',
            'language' => 'ru',
            'timezone' => 'UTC',
        ]);
        ClientChannelIdentity::factory()->forClient($client)->create([
            'channel' => 'telegram',
            'external_id' => 'client-'.$client->getKey(),
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create(['name' => 'Евгений Чуклов']);
        OrganizationChannelIdentity::factory()->forUser($staff)->verified()->create(['external_id' => 'staff-'.$staff->getKey()]);
        $specialist = Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'Евгений Чуклов',
            'staff_user_id' => $staff->getKey(),
            'timezone' => 'UTC',
        ]);
        $service = Service::factory()->forOrganization($organization)->create([
            'name' => 'Массаж тела',
            'formats' => ['office', 'home', 'online'],
        ]);

        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client, $specialist, $service];
    }

    private function booking(
        Organization $organization,
        Client $client,
        Specialist $specialist,
        Service $service,
        VisitFormat $format,
        CarbonImmutable $startsAt,
        ?string $location,
        BookingStatus $status = BookingStatus::Confirmed,
        ?MeetingLinkMode $meetingLinkMode = null,
        ?string $meetingUrl = null,
    ): Booking {
        return Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => $status->value,
                'visit_format' => $format->value,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addHour(),
                'blocking_ends_at' => $startsAt->addHour(),
                'schedule_timezone' => 'UTC',
                'client_timezone' => 'UTC',
                'location' => $location,
                'meeting_link_mode' => $meetingLinkMode?->value,
                'meeting_url' => $meetingUrl,
            ]);
    }

    private function makeDue(ScenarioAction $action): void
    {
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
    }
}
