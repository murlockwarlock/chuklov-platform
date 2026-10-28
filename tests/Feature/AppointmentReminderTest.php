<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Identity\Application\CreatePatientProfile;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\AppointmentReminderScheduler;
use App\Modules\Scenarios\Application\EnsureAppointmentReminderDefaults;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Application\UpdateAppointmentReminders;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Models\AppointmentReminder;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioDeliveryAttempt;
use App\Modules\Scheduling\Application\CancelBooking;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\PatientNotificationRecipientPolicy;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Database\Seeders\ScenarioNotificationSeeder;
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

        self::assertCount(5, $actions);
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
            ['database', 'telegram'],
            $actions
                ->where('recipient_type', 'internal')
                ->map(fn (ScenarioAction $action): string => $action->channel_priority[0])
                ->sort()
                ->values()
                ->all(),
        );
        self::assertSame(
            ['2026-09-05 13:30:00'],
            $actions
                ->where('recipient_type', 'internal')
                ->pluck('scheduled_for')
                ->map(fn (mixed $scheduledFor): string => CarbonImmutable::parse((string) $scheduledFor)->utc()->format('Y-m-d H:i:s'))
                ->unique()
                ->values()
                ->all(),
        );
        self::assertSame(ScenarioActionStatus::Scheduled, $actions->first()->status);
    }

    public function test_booking_reminder_recipients_follow_the_owner_policy_and_reload_idempotently(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $patient = app(CreatePatientProfile::class)->handle($client, [
            'first_name' => 'Synthetic',
            'last_name' => 'Patient',
            'middle_name' => null,
            'gender' => 'FEMALE',
            'birth_date' => '1990-01-01',
            'relationship' => 'SPOUSE',
        ], '00000000-0000-4000-8000-000000000011');
        $linked = Client::factory()->forOrganization($organization)->create([
            'birth_date' => '1990-01-01',
            'language' => 'ru',
            'timezone' => 'UTC',
        ]);
        $patient->forceFill(['linked_account_client_id' => $linked->getKey()])->save();

        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::PatientNotificationRecipients,
            PatientNotificationRecipientPolicy::PatientOnly->value,
        );
        $patientBooking = $this->booking(
            $organization,
            $client,
            $specialist,
            $service,
            VisitFormat::Office,
            CarbonImmutable::now()->addDays(3),
            'Synthetic office',
        );
        $patientBooking->forceFill(['patient_profile_id' => $patient->getKey()])->save();
        $patientEvent = app(RecordScenarioEvent::class)->bookingConfirmed($patientBooking, 'recipient-policy-patient', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($patientBooking, $patientEvent);
        app(AppointmentReminderScheduler::class)->schedule($patientBooking, $patientEvent);

        $patientActions = ScenarioAction::query()->where('booking_id', $patientBooking->getKey())->where('kind', 'appointment_reminder')->get();
        self::assertGreaterThan(0, $patientActions->count());
        self::assertSame([$linked->getKey()], $patientActions->pluck('client_id')->filter()->unique()->values()->all());

        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::PatientNotificationRecipients,
            PatientNotificationRecipientPolicy::BookerOnly->value,
        );
        $bookerBooking = $this->booking(
            $organization,
            $client,
            $specialist,
            $service,
            VisitFormat::Office,
            CarbonImmutable::now()->addDays(4),
            'Synthetic office',
        );
        $bookerBooking->forceFill(['patient_profile_id' => $patient->getKey()])->save();
        $bookerEvent = app(RecordScenarioEvent::class)->bookingConfirmed($bookerBooking, 'recipient-policy-booker', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($bookerBooking, $bookerEvent);

        $bookerActions = ScenarioAction::query()->where('booking_id', $bookerBooking->getKey())->where('kind', 'appointment_reminder')->get();
        self::assertGreaterThan(0, $bookerActions->count());
        self::assertSame([$client->getKey()], $bookerActions->pluck('client_id')->filter()->unique()->values()->all());
        self::assertSame(
            $patientActions->count(),
            ScenarioAction::query()->where('booking_id', $patientBooking->getKey())->where('kind', 'appointment_reminder')->count(),
        );
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

        self::assertCount(3, $actions);
        self::assertEqualsCanonicalizing([30, 30, 30], $actions->pluck('appointmentReminder.offset_value')->all());
        self::assertSame(2, $actions->pluck('appointment_reminder_id')->unique()->count());
        self::assertSame(3, $actions->flatMap(fn (ScenarioAction $action) => $action->deliveries)->count());
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

        self::assertSame(5, ScenarioAction::query()->where('booking_id', $booking->getKey())->where('status', ScenarioActionStatus::Cancelled->value)->count());
        self::assertSame(5, ScenarioAction::query()->where('booking_id', $booking->getKey())->where('status', ScenarioActionStatus::Scheduled->value)->count());
        self::assertSame(5, ScenarioAction::query()->where('booking_id', $booking->getKey())->where('status', ScenarioActionStatus::Cancelled->value)->whereHas('deliveries', fn ($query) => $query->where('status', ScenarioDeliveryStatus::Suppressed->value))->count());
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
        self::assertSame(5, ScenarioAction::query()->where('booking_id', $booking->getKey())->where('status', ScenarioActionStatus::Cancelled->value)->count());
        self::assertSame(5, ScenarioAction::query()->where('booking_id', $booking->getKey())->whereHas('deliveries', fn ($query) => $query->where('status', ScenarioDeliveryStatus::Suppressed->value))->count());
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

    public function test_medical_prompt_uses_live_completion_and_a_real_mini_app_button_without_duplicates(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $service->forceFill(['requires_medical_profile' => true])->save();
        $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office');
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'medical-test', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($booking, $event);
        app(AppointmentReminderScheduler::class)->schedule($booking, $event);
        $actions = ScenarioAction::query()->where('booking_id', $booking->id)->where('recipient_type', 'client')
            ->whereHas('appointmentReminder', fn ($query) => $query->where('medical_profile_prompt', true))
            ->with('appointmentReminder')->get();
        self::assertCount(2, $actions);
        $daily = $actions->first(fn ($action) => $action->appointmentReminder->offset_unit === ScenarioDelayUnit::Days);
        $this->makeDue($daily);
        app(ExecuteScenarioAction::class)->handle($daily->id);
        app(ExecuteScenarioAction::class)->handle($daily->id);
        self::assertCount(1, $this->channel->messages);
        $message = $this->channel->messages[0];
        self::assertSame(
            'До вашего сеанса осталось 24 часа! Пожалуйста, уделите 3 минуты на заполнение жалоб, чтобы мастер встретил вас с готовой стратегией:',
            $message->body,
        );
        self::assertSame('Заполнить карту здоровья', $message->actionButton?->text);
        self::assertSame('https://mini.example.test/portal/telegram/launch/medical_profile', $message->actionButton?->webAppUrl);
        self::assertNull($message->actionButton?->url);
        self::assertSame(ScenarioActionStatus::Delivered, $daily->fresh()->status);
        $sentVersion = ScenarioDeliveryAttempt::query()->whereHas('delivery', fn ($query) => $query->where('scenario_action_id', $daily->id))->sole()->template_version_id;
        self::assertSame($daily->render_context['medical_profile_reminder']['template_version_id'], $sentVersion);

        $profile = new MedicalProfile;
        $profile->forceFill(['organization_id' => $organization->id, 'client_id' => $client->id,
            'encryption_key_version' => 1, 'client_confirmed_at' => now()])->save();
        $hourly = $actions->first(fn ($action) => $action->appointmentReminder->offset_unit === ScenarioDelayUnit::Hours);
        $this->makeDue($hourly);
        app(ExecuteScenarioAction::class)->handle($hourly->id);
        self::assertCount(2, $this->channel->messages);
        self::assertSame(
            "Напоминаем: у вас запись к Евгений Чуклов 05.09.2026 в 14:00.\nАдрес: Synthetic office",
            $this->channel->messages[1]->body,
        );
        self::assertNull($this->channel->messages[1]->actionButton);
        self::assertSame($hourly->template_version_id, ScenarioDeliveryAttempt::query()
            ->whereHas('delivery', fn ($query) => $query->where('scenario_action_id', $hourly->id))->sole()->template_version_id);
    }

    public function test_late_reminder_does_not_send_after_the_visit_has_started(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office');
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'late-test', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($booking, $event);
        $action = ScenarioAction::query()->where('booking_id', $booking->id)->where('recipient_type', 'client')->firstOrFail();
        CarbonImmutable::setTestNow($booking->startsAtUtc());
        app(ExecuteScenarioAction::class)->handle($action->id);
        self::assertCount(0, $this->channel->messages);
        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
    }

    public function test_requested_medical_booking_materializes_the_full_cascade_once_and_uses_its_actual_status(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $service->forceFill(['requires_medical_profile' => true])->save();
        $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office', BookingStatus::Requested);
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'created-medical-test', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->id);
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $immediate = ScenarioAction::query()->where('booking_id', null)->where('scenario_event_id', $event->id)->sole();
        $reminders = ScenarioAction::query()->where('booking_id', $booking->id)->with('appointmentReminder')->get();
        self::assertCount(2, $reminders);
        app(ExecuteScenarioAction::class)->handle($immediate->id);
        app(ExecuteScenarioAction::class)->handle($immediate->id);
        self::assertCount(1, $this->channel->messages);
        self::assertSame(
            'Запись создана! Чтобы Евгений Чуклов заранее ознакомился с вашей ситуацией и подготовил индивидуальный план — заполните короткую карту здоровья:',
            $this->channel->messages[0]->body,
        );
        self::assertStringNotContainsString('подтверждено', $this->channel->messages[0]->body);
        self::assertSame('Заполнить карту здоровья', $this->channel->messages[0]->actionButton?->text);
        self::assertSame('https://mini.example.test/portal/telegram/launch/medical_profile', $this->channel->messages[0]->actionButton?->webAppUrl);
        self::assertNull($this->channel->messages[0]->actionButton?->url);
        self::assertSame(BookingStatus::Requested, $booking->fresh()->status);

        $hourly = $reminders->first(fn ($action) => $action->appointmentReminder->offset_unit === ScenarioDelayUnit::Hours);
        $this->makeDue($hourly);
        app(ExecuteScenarioAction::class)->handle($hourly->id);
        self::assertCount(2, $this->channel->messages);
        self::assertSame(
            'До вашего сеанса осталось 2 часа! Пожалуйста, уделите 3 минуты на заполнение жалоб, чтобы мастер встретил вас с готовой стратегией:',
            $this->channel->messages[1]->body,
        );
        self::assertSame('Заполнить карту здоровья', $this->channel->messages[1]->actionButton?->text);
        self::assertSame('https://mini.example.test/portal/telegram/launch/medical_profile', $this->channel->messages[1]->actionButton?->webAppUrl);
        self::assertNull($this->channel->messages[1]->actionButton?->url);
        self::assertSame(2, ScenarioDeliveryAttempt::query()->count());
        self::assertSame(ScenarioActionStatus::Delivered, $hourly->fresh()->status);
    }

    public function test_confirmed_medical_creation_uses_status_accurate_copy_and_profile_cta(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $service->forceFill(['requires_medical_profile' => true])->save();
        app(ScenarioNotificationSeeder::class)->run();
        $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office', BookingStatus::Confirmed);
        $booking->forceFill(['source' => 'portal'])->save();
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'created-confirmed-medical-test', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->where('scenario_event_id', $event->id)
            ->where('recipient_type', 'client')->where('kind', 'scenario')->sole();
        $this->makeDue($action);
        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertCount(1, $this->channel->messages);
        self::assertSame(
            'Бронирование подтверждено! Чтобы Евгений Чуклов заранее ознакомился с вашей ситуацией и подготовил индивидуальный план — заполните короткую карту здоровья:',
            $this->channel->messages[0]->body,
        );
        self::assertSame('Заполнить карту здоровья', $this->channel->messages[0]->actionButton?->text);
        self::assertSame('https://mini.example.test/portal/telegram/launch/medical_profile', $this->channel->messages[0]->actionButton?->webAppUrl);
        self::assertNull($this->channel->messages[0]->actionButton?->url);
        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
    }

    public function test_completing_profile_after_materialization_suppresses_pending_prompts(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $service->forceFill(['requires_medical_profile' => true])->save();
        $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office', BookingStatus::Requested);
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'created-completed-test', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $profile = new MedicalProfile;
        $profile->forceFill(['organization_id' => $organization->id, 'client_id' => $client->id,
            'encryption_key_version' => 1, 'client_confirmed_at' => now()])->save();
        foreach (ScenarioAction::query()->where('scenario_event_id', $event->id)->get() as $action) {
            $this->makeDue($action);
            app(ExecuteScenarioAction::class)->handle($action->id);
            self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        }
        self::assertCount(0, $this->channel->messages);
        self::assertSame(0, ScenarioDeliveryAttempt::query()->count());
    }

    public function test_prompt_checkbox_can_be_disabled_without_disabling_standard_reminders(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $service->forceFill(['requires_medical_profile' => true])->save();
        $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office');
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'disable-medical-test', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($booking, $event);
        app(UpdateAppointmentReminders::class)->handle($admin, [
            'client_reminders' => [['offset_value' => 2, 'offset_unit' => 'hours', 'is_enabled' => true, 'medical_profile_prompt' => false]],
            'specialist_reminders' => [],
        ]);
        $action = ScenarioAction::query()->where('booking_id', $booking->id)->where('status', ScenarioActionStatus::Scheduled)->sole();
        $this->makeDue($action);
        app(ExecuteScenarioAction::class)->handle($action->id);
        self::assertCount(1, $this->channel->messages);
        self::assertStringContainsString('Synthetic office', $this->channel->messages[0]->body);
        self::assertNull($this->channel->messages[0]->actionButton);
    }

    public function test_malformed_and_stale_event_payloads_cannot_schedule_reminders(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office');
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'invalid-payload-test', CarbonImmutable::now());
        foreach ([null, 'invalid', [], ['booking_id' => [$booking->id], 'event_version' => 1],
            ['booking_id' => $booking->id, 'event_version' => 0]] as $payload) {
            $event->setAttribute('payload', $payload);
            app(AppointmentReminderScheduler::class)->schedule($booking, $event);
            self::assertSame(0, ScenarioAction::query()->where('organization_id', $organization->id)->count());
        }
    }

    public function test_medical_creation_is_one_delivery_regardless_of_rule_creation_order(): void
    {
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        foreach ([false, true] as $reverse) {
            [$organization, , $client, $specialist, $service] = $this->fixture();
            $service->forceFill(['requires_medical_profile' => true])->save();
            if ($reverse) {
                app(EnsureAppointmentReminderDefaults::class)->handle($organization);
            }
            app(ScenarioNotificationSeeder::class)->run();
            $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
                CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office', BookingStatus::Requested);
            $booking->forceFill(['source' => 'portal'])->save();
            $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'medical-creation-dedup', CarbonImmutable::now());
            app(MaterializeScenarioEvent::class)->handle($event->id);
            $actions = ScenarioAction::query()->where('scenario_event_id', $event->id)->where('recipient_type', 'client')
                ->where('kind', 'scenario')->orderBy('id')->get();
            self::assertCount(1, $actions);
            foreach ($reverse ? $actions->reverse() : $actions as $action) {
                $this->makeDue($action);
                app(ExecuteScenarioAction::class)->handle($action->id);
            }
            $messages = array_values(array_filter($this->channel->messages, fn ($message) => $message->recipientExternalId === 'client-'.$client->id));
            self::assertCount(1, $messages);
            self::assertStringStartsWith('Запись создана!', $messages[0]->body);
            self::assertSame('Заполнить карту здоровья', $messages[0]->actionButton?->text);
            self::assertSame(1, ScenarioAction::query()->whereIn('id', $actions->modelKeys())->where('status', ScenarioActionStatus::Delivered)->count());
            self::assertSame(1, ScenarioDeliveryAttempt::query()->whereHas('delivery', fn ($query) => $query->whereIn('scenario_action_id', $actions->modelKeys()))->count());
        }
    }

    public function test_completion_before_delivery_keeps_the_standard_creation_confirmation(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $service->forceFill(['requires_medical_profile' => true])->save();
        app(ScenarioNotificationSeeder::class)->run();
        $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office', BookingStatus::Requested);
        $booking->forceFill(['source' => 'portal'])->save();
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'completed-before-creation-delivery', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->where('scenario_event_id', $event->id)->where('recipient_type', 'client')->where('kind', 'scenario')->sole();
        $profile = new MedicalProfile;
        $profile->forceFill(['organization_id' => $organization->id, 'client_id' => $client->id,
            'encryption_key_version' => 1, 'client_confirmed_at' => now()])->save();
        $this->makeDue($action);
        app(ExecuteScenarioAction::class)->handle($action->id);
        app(ExecuteScenarioAction::class)->handle($action->id);
        self::assertCount(1, $this->channel->messages);
        self::assertStringStartsWith('Заявка на запись принята', $this->channel->messages[0]->body);
        self::assertNull($this->channel->messages[0]->actionButton);
        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
    }

    public function test_a_late_provider_result_cannot_finalize_a_newer_delivery_claim(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office');
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'late-provider-test', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($booking, $event);
        $action = ScenarioAction::query()->where('booking_id', $booking->id)->where('recipient_type', 'client')->firstOrFail();
        $this->makeDue($action);
        $this->channel->onSend = static function () use ($action, $organization): void {
            $current = ScenarioDelivery::query()->where('scenario_action_id', $action->id)->sole();
            $current->forceFill(['attempt_count' => 2, 'processing_started_at' => CarbonImmutable::now('UTC')->addSecond()])->save();
            $newAttempt = new ScenarioDeliveryAttempt;
            $newAttempt->forceFill(['organization_id' => $organization->id, 'scenario_delivery_id' => $current->id,
                'attempt_number' => 2, 'outcome' => NotificationDeliveryOutcome::Unknown, 'attempted_at' => now()])->save();
        };
        app(ExecuteScenarioAction::class)->handle($action->id);
        self::assertCount(1, $this->channel->messages);
        $delivery = $action->deliveries()->sole();
        self::assertSame(ScenarioDeliveryStatus::Processing, $delivery->status);
        self::assertSame(2, $delivery->attempt_count);
        self::assertSame(ScenarioActionStatus::Processing, $action->fresh()->status);
        $attempts = $delivery->attempts()->orderBy('attempt_number')->get();
        self::assertCount(2, $attempts);
        self::assertSame(NotificationDeliveryOutcome::Unknown, $attempts[0]->outcome);
        self::assertSame(NotificationDeliveryOutcome::Unknown, $attempts[1]->outcome);
        self::assertSame($action->template_version_id, $attempts[0]->template_version_id);
        self::assertNull($attempts[1]->template_version_id);
        app(ExecuteScenarioAction::class)->handle($action->id);
        self::assertCount(1, $this->channel->messages);
    }

    public function test_cancellation_before_delivery_suppresses_the_entire_creation_notice(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $service->forceFill(['requires_medical_profile' => true])->save();
        app(ScenarioNotificationSeeder::class)->run();
        $booking = $this->booking($organization, $client, $specialist, $service, VisitFormat::Office,
            CarbonImmutable::create(2026, 9, 5, 14, 0, 0, 'UTC'), 'Synthetic office', BookingStatus::Requested);
        $booking->forceFill(['source' => 'portal'])->save();
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'cancel-creation-test', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $notice = ScenarioAction::query()->where('scenario_event_id', $event->id)->where('recipient_type', 'client')->where('kind', 'scenario')->sole();
        app(CancelBooking::class)->handle($admin, $booking, 'Synthetic cancellation');
        $this->makeDue($notice);
        app(ExecuteScenarioAction::class)->handle($notice->id);
        self::assertCount(0, $this->channel->messages);
        self::assertSame(ScenarioActionStatus::Suppressed, $notice->fresh()->status);
        self::assertSame(0, $notice->deliveries()->sole()->attempts()->count());
    }

    /** @return array{Organization, User, Client, Specialist, Service} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $organization->featureFlags()->create(['feature_key' => OrganizationFeature::ClientRecords->value, 'enabled' => true]);
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
            'requires_medical_profile' => false,
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
