<?php

namespace Tests\Feature;

use App\Filament\Resources\ScenarioRules\Pages\CreateScenarioRule;
use App\Filament\Resources\ScenarioRules\Pages\EditScenarioRule;
use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\NotificationActionButton;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\CreateNotificationTemplate;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Application\ScheduleScenarioWork;
use App\Modules\Scenarios\Application\UpdateNotificationTemplate;
use App\Modules\Scenarios\Application\UpdateScenarioRule;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventStatus;
use App\Modules\Scenarios\Domain\Exceptions\FeedbackMiniAppConfigurationException;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioIdempotencyKey;
use App\Modules\Scenarios\Jobs\ExecuteScenarioAction as ExecuteScenarioActionJob;
use App\Modules\Scenarios\Jobs\ProcessScenarioEvent;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Application\SetOnlineMeetingUrl;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Application\UpdateSpecialist;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistNotificationSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\ScenarioNotificationSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class MilestoneFiveScenarioTest extends TestCase
{
    use RefreshDatabase;

    private RecordingNotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$this->channel]));
    }

    public function test_booking_completion_publishes_a_durable_scenario_event_atomically(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Confirmed);
        app(OrganizationContext::class)->set($organization);

        $completed = app(CompleteBooking::class)->handle($admin, $booking);

        self::assertSame(BookingStatus::Completed, $completed->status);
        self::assertSame(1, DB::table('booking_events')->where('booking_id', $booking->id)->count());
        $event = ScenarioEvent::query()->where('organization_id', $organization->id)->sole();
        self::assertSame(ScenarioEventStatus::Pending, $event->status);
        self::assertSame('booking.completed', $event->event_name->value);
        self::assertSame($booking->id, $event->payload['booking_id']);
        self::assertSame('booking.completed:'.$organization->id.':'.$booking->id.':2', $event->idempotency_key);
    }

    public function test_confirmed_online_booking_notifies_client_with_schedule_and_inline_meeting_button(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        app(ScenarioNotificationSeeder::class)->run();
        app(OrganizationContext::class)->set($organization);
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Requested,
                'visit_format' => VisitFormat::Online,
                'meeting_link_mode' => MeetingLinkMode::Manual,
                'starts_at' => CarbonImmutable::create(2026, 9, 5, 10, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 5, 11, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 5, 11, 0, 0, 'UTC'),
                'schedule_timezone' => 'UTC',
                'client_timezone' => 'UTC',
            ]);

        $confirmed = app(ConfirmBooking::class)->handle($admin, $booking);
        $updated = app(SetOnlineMeetingUrl::class)->handle($admin, $confirmed, 'https://zoom.us/j/ordinary-1?pwd=test-password');
        $event = ScenarioEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event_name', 'booking.confirmed')
            ->where('payload->event_version', $updated->event_version)
            ->sole();

        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = ScenarioAction::query()->where('scenario_event_id', $event->getKey())->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($action->getKey());

        $message = $this->channel->messages[0] ?? null;
        self::assertNotNull($message);
        self::assertStringContainsString('Appointment confirmed', $message->body);
        self::assertStringContainsString($specialist->display_name, $message->body);
        self::assertStringContainsString($service->name, $message->body);
        self::assertStringContainsString('Online', $message->body);
        self::assertStringNotContainsString('https://zoom.us/j/ordinary-1', $message->body);
        self::assertInstanceOf(NotificationActionButton::class, $message->actionButton);
        self::assertSame('https://zoom.us/j/ordinary-1?pwd=test-password', $message->actionButton->url);
        self::assertSame('Join meeting', $message->actionButton->text);
    }

    public function test_confirmed_auto_online_booking_waits_for_zoom_and_keeps_specialist_context(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $client->forceFill(['full_name' => 'Aikhana', 'language' => 'ru'])->save();
        $this->verifiedTelegramIdentity($organization, $client);
        ClientChannelIdentity::query()->where('client_id', $client->getKey())->sole()->forceFill([
            'external_id' => '123456789',
            'external_username' => 'aikhana',
        ])->save();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $specialist->forceFill(['staff_user_id' => $staff->getKey()])->save();
        OrganizationChannelIdentity::factory()->forUser($staff)->verified()->create();
        app(ScenarioNotificationSeeder::class)->run();
        app(OrganizationContext::class)->set($organization);

        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Requested,
                'visit_format' => VisitFormat::Online,
                'meeting_link_mode' => MeetingLinkMode::Auto,
                'provider_account_id' => 'account-a',
                'provider_host_user_id' => 'host-a',
                'provider_sync_status' => 'pending',
                'provider_operation' => 'create',
                'provider_correlation_key' => 'booking-confirmation-test',
                'starts_at' => CarbonImmutable::create(2026, 9, 5, 10, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 5, 11, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 5, 11, 0, 0, 'UTC'),
                'schedule_timezone' => 'UTC',
                'client_timezone' => 'UTC',
            ]);
        $confirmed = app(ConfirmBooking::class)->handle($admin, $booking);
        $event = ScenarioEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event_name', 'booking.confirmed')
            ->sole();

        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        self::assertSame(ScenarioEventStatus::Pending, $event->fresh()->status);
        self::assertSame('booking_meeting_pending', $event->fresh()->last_error_code);
        self::assertSame(0, ScenarioAction::query()->where('scenario_event_id', $event->getKey())->where('kind', 'scenario')->count());

        $readyAt = CarbonImmutable::parse((string) $event->fresh()->available_at)->addSecond();
        CarbonImmutable::setTestNow($readyAt);
        $confirmed->forceFill([
            'provider_sync_status' => 'ready',
            'provider_operation' => null,
            'provider_join_url' => 'https://zoom.us/j/confirmed-auto',
        ])->save();

        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $actions = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->where('kind', 'scenario')
            ->orderBy('recipient_type')
            ->get();

        self::assertCount(2, $actions);
        $specialistAction = $actions->firstWhere('recipient_type', 'internal');
        self::assertNotNull($specialistAction);
        self::assertSame('Aikhana', $specialistAction->render_context['client']['full_name']);
        self::assertSame('@aikhana (ID: 123456789)', $specialistAction->render_context['client']['telegram_contact']);
        self::assertSame('tg://user?id=123456789', $specialistAction->render_context['client']['telegram_profile_url']);

        foreach ($actions as $action) {
            $this->makeDue($action);
            app(ExecuteScenarioAction::class)->handle($action->getKey());
        }

        $clientMessage = collect($this->channel->messages)->firstWhere('recipientExternalId', '123456789');
        $specialistMessage = collect($this->channel->messages)->firstWhere('recipientExternalId', $specialistAction->recipient_user_id === $staff->getKey()
            ? OrganizationChannelIdentity::query()->where('user_id', $staff->getKey())->value('external_id')
            : null);
        self::assertNotNull($clientMessage);
        self::assertStringContainsString('Запись подтверждена', $clientMessage->body);
        self::assertStringContainsString('Онлайн', $clientMessage->body);
        self::assertSame('https://zoom.us/j/confirmed-auto', $clientMessage->actionButton?->url);
        self::assertNotNull($specialistMessage);
        self::assertStringContainsString('Aikhana', $specialistMessage->body);
        self::assertStringContainsString('@aikhana (ID: 123456789)', $specialistMessage->body);
        self::assertSame('tg://user?id=123456789', $specialistMessage->actionButton?->url);
        self::assertStringNotContainsString('не указан', $specialistMessage->body);
    }

    public function test_new_booking_notifies_client_and_assigned_specialist(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $specialist->forceFill(['staff_user_id' => $staff->getKey()])->save();
        $staffIdentity = OrganizationChannelIdentity::factory()->forUser($staff)->verified()->create();
        app(ScenarioNotificationSeeder::class)->run();

        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Requested);
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'booking-created-test', CarbonImmutable::now());

        self::assertSame('booking.created', $event->event_name->value);
        self::assertSame($booking->id, $event->payload['booking_id']);
        self::assertSame($client->id, $event->payload['client_id']);
        self::assertSame($specialist->id, $event->payload['specialist_id']);

        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        $actions = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->orderBy('recipient_type')
            ->get();
        self::assertCount(2, $actions);
        self::assertSame($client->id, $actions->firstWhere('recipient_type', 'client')?->client_id);
        self::assertSame($staff->id, $actions->firstWhere('recipient_type', 'internal')?->recipient_user_id);

        foreach ($actions as $action) {
            $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
            $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
            app(ExecuteScenarioAction::class)->handle($action->getKey());
        }

        self::assertCount(2, $this->channel->messages);
        self::assertEqualsCanonicalizing(
            [$client->getKey().'-chat', $staffIdentity->external_id],
            array_map(static fn (NotificationMessage $message): string => $message->recipientExternalId, $this->channel->messages),
        );
        self::assertStringContainsString('Appointment request received', $this->channel->messages[0]->body);
        self::assertStringContainsString($specialist->display_name, $this->channel->messages[0]->body);
        self::assertStringContainsString('Новая заявка на запись от клиента '.$client->full_name, $this->channel->messages[1]->body);
        self::assertStringContainsString(
            '@client_'.$client->id.' (ID: '.$client->id.'-chat)',
            $this->channel->messages[1]->body,
        );
        self::assertSame('📋 Открыть запись в CRM', $this->channel->messages[1]->actionButtons[0]->text);
        self::assertSame(url('/admin/bookings/'.$booking->getKey()), $this->channel->messages[1]->actionButtons[0]->url);
        self::assertSame('✅ Подтвердить', $this->channel->messages[1]->actionButtons[1]->text);
        self::assertSame('booking:confirm:'.$booking->getKey().':'.$booking->event_version, $this->channel->messages[1]->actionButtons[1]->callbackData);
    }

    public function test_pending_home_visit_notification_only_offers_review_link(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $specialist->forceFill(['staff_user_id' => $admin->getKey()])->save();
        $staffIdentity = OrganizationChannelIdentity::factory()->forUser($admin)->verified()->create();
        app(ScenarioNotificationSeeder::class)->run();

        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::PendingReview);
        $booking->forceFill([
            'visit_format' => VisitFormat::HomeVisit,
            'location' => '123 Moo 5, Bang Tao',
            'location_area' => 'Bang Tao',
            'location_snapshot' => [
                'type' => VisitFormat::HomeVisit->value,
                'area_name' => 'Bang Tao',
                'address' => '123 Moo 5, Bang Tao',
                'timezone' => 'Asia/Bangkok',
            ],
        ])->save();
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'pending-home-visit-review', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->where('recipient_type', 'internal')
            ->sole();

        $this->makeDue($action);
        app(ExecuteScenarioAction::class)->handle($action->getKey());

        $message = collect($this->channel->messages)->firstWhere('recipientExternalId', $staffIdentity->external_id);
        self::assertNotNull($message);
        self::assertCount(1, $message->actionButtons);
        self::assertSame('🚗 Рассмотреть выезд', $message->actionButtons[0]->text);
        self::assertSame(url('/admin/bookings/'.$booking->getKey()), $message->actionButtons[0]->url);
        self::assertNull($message->actionButtons[0]->callbackData);
        self::assertSame(BookingStatus::PendingReview, $booking->refresh()->status);
    }

    public function test_delayed_booking_notification_does_not_offer_confirmation_after_booking_changes(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $specialist->forceFill(['staff_user_id' => $staff->getKey()])->save();
        $staffIdentity = OrganizationChannelIdentity::factory()->forUser($staff)->verified()->create();
        app(ScenarioNotificationSeeder::class)->run();

        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Requested);
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'stale-booking-confirmation', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->where('recipient_type', 'internal')
            ->sole();

        app(OrganizationContext::class)->set($organization);
        app(ConfirmBooking::class)->handle($admin, $booking);
        $this->makeDue($action);
        app(ExecuteScenarioAction::class)->handle($action->getKey());

        $message = collect($this->channel->messages)->firstWhere('recipientExternalId', $staffIdentity->external_id);
        self::assertNotNull($message);
        self::assertCount(1, $message->actionButtons);
        self::assertSame(url('/admin/bookings/'.$booking->getKey()), $message->actionButtons[0]->url);
    }

    public function test_specialist_notification_without_username_uses_verified_id_and_profile_action(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        ClientChannelIdentity::factory()->forClient($client)->create([
            'external_id' => '987654321',
            'external_username' => null,
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $specialist->forceFill(['staff_user_id' => $staff->getKey()])->save();
        OrganizationChannelIdentity::factory()->forUser($staff)->verified()->create();
        app(ScenarioNotificationSeeder::class)->run();
        app(OrganizationContext::class)->set($organization);

        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Requested);
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'booking-created-no-username', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->where('recipient_type', 'internal')
            ->sole();

        $this->makeDue($action);
        app(ExecuteScenarioAction::class)->handle($action->getKey());

        $message = collect($this->channel->messages)->firstWhere('recipientExternalId', OrganizationChannelIdentity::query()
            ->where('user_id', $staff->getKey())
            ->value('external_id'));
        self::assertNotNull($message);
        self::assertStringContainsString('ID: 987654321', $message->body);
        self::assertStringNotContainsString('@', $message->body);
        self::assertStringNotContainsString('не указан', $message->body);
        self::assertSame('tg://user?id=987654321', $message->actionButton?->url);
    }

    public function test_rescheduled_and_cancelled_bookings_notify_with_local_date_format(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        app(ScenarioNotificationSeeder::class)->run();

        $rescheduled = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Requested,
                'starts_at' => CarbonImmutable::create(2026, 9, 4, 5, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 4, 6, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 4, 6, 0, 0, 'UTC'),
                'schedule_timezone' => 'Asia/Bangkok',
                'client_timezone' => 'Asia/Bangkok',
            ]);
        $rescheduledEvent = app(RecordScenarioEvent::class)->bookingRescheduled($rescheduled, 'booking-rescheduled-test', CarbonImmutable::now());

        self::assertSame('booking.rescheduled', $rescheduledEvent->event_name->value);
        self::assertSame('Asia/Bangkok', $rescheduledEvent->payload['schedule_timezone']);
        self::assertSame($rescheduled->event_version, $rescheduledEvent->payload['event_version']);

        app(MaterializeScenarioEvent::class)->handle($rescheduledEvent->getKey());
        $rescheduledAction = ScenarioAction::query()->where('scenario_event_id', $rescheduledEvent->getKey())->sole();
        $this->makeDue($rescheduledAction);
        app(ExecuteScenarioAction::class)->handle($rescheduledAction->getKey());

        self::assertSame(ScenarioActionStatus::Delivered, $rescheduledAction->fresh()->status);
        self::assertStringContainsString('04-09-2026 · 12:00 (Asia/Bangkok)', $this->channel->messages[0]->body);
        self::assertStringContainsString('At the clinic', $this->channel->messages[0]->body);

        $cancelled = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Cancelled,
                'starts_at' => CarbonImmutable::create(2026, 9, 5, 5, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 5, 6, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 5, 6, 0, 0, 'UTC'),
                'schedule_timezone' => 'Asia/Bangkok',
                'client_timezone' => 'Asia/Bangkok',
            ]);
        $cancelledEvent = app(RecordScenarioEvent::class)->bookingCancelled($cancelled, 'booking-cancelled-test', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($cancelledEvent->getKey());
        $cancelledAction = ScenarioAction::query()->where('scenario_event_id', $cancelledEvent->getKey())->sole();
        $this->makeDue($cancelledAction);
        app(ExecuteScenarioAction::class)->handle($cancelledAction->getKey());

        self::assertSame(ScenarioActionStatus::Delivered, $cancelledAction->fresh()->status);
        self::assertStringContainsString('05-09-2026 · 12:00 (Asia/Bangkok)', $this->channel->messages[1]->body);
        self::assertStringContainsString('At the clinic', $this->channel->messages[1]->body);
    }

    public function test_auto_rescheduled_notification_waits_for_the_ready_meeting_link(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        app(ScenarioNotificationSeeder::class)->run();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Requested,
                'visit_format' => VisitFormat::Online,
                'meeting_link_mode' => MeetingLinkMode::Auto,
                'provider_sync_status' => 'pending',
                'provider_join_url' => null,
                'starts_at' => CarbonImmutable::create(2026, 9, 4, 5, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 4, 6, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 4, 6, 0, 0, 'UTC'),
                'schedule_timezone' => 'Asia/Bangkok',
                'client_timezone' => 'Asia/Bangkok',
            ]);
        $event = app(RecordScenarioEvent::class)->bookingRescheduled($booking, 'booking-rescheduled-meeting-pending', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        self::assertSame(ScenarioEventStatus::Pending, $event->fresh()->status);
        self::assertSame('booking_meeting_pending', $event->fresh()->last_error_code);
        self::assertSame(0, ScenarioAction::query()->where('scenario_event_id', $event->getKey())->count());

        $booking->forceFill([
            'provider_sync_status' => 'ready',
            'provider_join_url' => 'https://zoom.us/j/rescheduled-ready',
        ])->save();
        $event->forceFill(['available_at' => now()->subSecond()])->save();

        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = ScenarioAction::query()->where('scenario_event_id', $event->getKey())->sole();
        $this->makeDue($action);
        app(ExecuteScenarioAction::class)->handle($action->getKey());

        self::assertSame('https://zoom.us/j/rescheduled-ready', $this->channel->messages[0]->actionButton?->url);
    }

    public function test_rescheduled_notification_is_suppressed_when_booking_changes_before_delivery(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        app(ScenarioNotificationSeeder::class)->run();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Requested,
                'starts_at' => CarbonImmutable::create(2026, 9, 4, 5, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 4, 6, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 4, 6, 0, 0, 'UTC'),
                'schedule_timezone' => 'Asia/Bangkok',
                'client_timezone' => 'Asia/Bangkok',
            ]);
        $event = app(RecordScenarioEvent::class)->bookingRescheduled($booking, 'booking-rescheduled-stale', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = ScenarioAction::query()->where('scenario_event_id', $event->getKey())->sole();
        $booking->forceFill([
            'starts_at' => CarbonImmutable::create(2026, 9, 4, 6, 0, 0, 'UTC'),
            'ends_at' => CarbonImmutable::create(2026, 9, 4, 7, 0, 0, 'UTC'),
            'blocking_ends_at' => CarbonImmutable::create(2026, 9, 4, 7, 0, 0, 'UTC'),
            'event_version' => 2,
        ])->save();
        $this->makeDue($action);

        app(ExecuteScenarioAction::class)->handle($action->getKey());

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertSame('booking_changed', $action->fresh()->terminal_reason);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_booking_completion_rolls_back_booking_history_and_scenario_event_together(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Confirmed);
        app(OrganizationContext::class)->set($organization);
        $throw = true;
        DB::listen(function (QueryExecuted $query) use (&$throw): void {
            if ($throw && str_contains(strtolower($query->sql), 'scenario_events')
                && str_starts_with(strtolower(trim($query->sql)), 'insert')) {
                $throw = false;
                throw new \RuntimeException('test failure');
            }
        });

        $this->expectException(\RuntimeException::class);
        try {
            app(CompleteBooking::class)->handle($admin, $booking);
        } finally {
            self::assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
            self::assertSame(0, DB::table('booking_events')->where('booking_id', $booking->id)->count());
            self::assertSame(0, ScenarioEvent::query()->where('organization_id', $organization->id)->count());
            self::assertSame(0, DB::table('audit_events')->where('organization_id', $organization->id)->where('action', 'booking.completed')->count());
        }
    }

    public function test_booking_completed_materializes_and_delivers_one_scheduled_action(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        $templateVersion = $this->template($organization);
        $rule = ScenarioRule::factory()
            ->forOrganization($organization)
            ->usingTemplate($templateVersion)
            ->create([
                'conditions' => [['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed']],
                'delay_value' => 24,
                'delay_unit' => 'hours',
            ]);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'booking-event-1', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($event->id);
        app(MaterializeScenarioEvent::class)->handle($event->id);

        self::assertSame(ScenarioEventStatus::Processed, $event->fresh()->status);
        self::assertSame(1, ScenarioAction::query()->where('scenario_rule_id', $rule->id)->count());
        $action = ScenarioAction::query()->sole();
        self::assertSame(ScenarioActionStatus::Scheduled, $action->status);
        self::assertSame(1, ScenarioDelivery::query()->where('scenario_action_id', $action->id)->count());
        self::assertSame(
            ScenarioIdempotencyKey::materialization($organization->id, $event->id, $rule->id, 'client:'.$client->id),
            $action->materialization_key,
        );

        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
        app(ExecuteScenarioAction::class)->handle($action->id);

        $action->refresh();
        self::assertSame(ScenarioActionStatus::Delivered, $action->status, json_encode([
            'action' => $action->status->value,
            'delivery' => $action->deliveries()->sole()->status->value,
            'error' => $action->deliveries()->sole()->last_error_code,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(ScenarioDeliveryStatus::Delivered, $action->deliveries()->sole()->status);
        self::assertSame(1, $action->deliveries()->sole()->attempts()->count());
        self::assertCount(1, $this->channel->messages);
        self::assertSame('Hello '.$client->full_name.'.', $this->channel->messages[0]->body);
        self::assertSame($action->deliveries()->sole()->idempotency_key, $this->channel->messages[0]->idempotencyKey);
    }

    public function test_booking_completion_flows_through_materialization_and_delivery(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        $templateVersion = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($templateVersion)->create([
            'rule_key' => 'booking-completion-vertical-slice',
            'conditions' => [['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed']],
        ]);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Confirmed);
        app(OrganizationContext::class)->set($organization);

        app(CompleteBooking::class)->handle($admin, $booking);
        $event = ScenarioEvent::query()->where('organization_id', $organization->id)->sole();
        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->getKey())->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($action->getKey());

        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame(ScenarioDeliveryStatus::Delivered, $action->deliveries()->sole()->status);
        self::assertCount(1, $this->channel->messages);
    }

    public function test_completed_booking_feedback_uses_the_existing_scenario_engine_and_is_idempotent(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        app(ScenarioNotificationSeeder::class)->run();
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Confirmed);
        app(OrganizationContext::class)->set($organization);

        app(CompleteBooking::class)->handle($admin, $booking);
        $event = ScenarioEvent::query()->where('organization_id', $organization->getKey())->sole();
        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        $rule = ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->where('rule_key', 'booking-completed-feedback-en')
            ->sole();
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->getKey())->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($action->getKey());
        app(ExecuteScenarioAction::class)->handle($action->getKey());

        $feedbackUrl = 'https://mini.example.test/portal/telegram/launch/feedback';
        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertCount(1, $this->channel->messages);
        self::assertSame('Please rate your visit, '.$client->full_name.'.', $this->channel->messages[0]->body);
        self::assertStringNotContainsString($feedbackUrl, $this->channel->messages[0]->body);
        self::assertSame($feedbackUrl, $this->channel->messages[0]->webAppUrl);
        self::assertSame(['client.full_name', 'feedback.url'], NotificationTemplateVersion::query()
            ->whereKey($rule->template_version_id)
            ->value('variables'));
    }

    public function test_feedback_delivery_fails_closed_without_the_canonical_mini_app_and_other_booking_rules_continue(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        config()->set('portal.telegram.portal_url', 'http://mini.example.test');
        app(ScenarioNotificationSeeder::class)->run();
        $ordinaryRule = ScenarioRule::factory()
            ->forOrganization($organization)
            ->usingTemplate($this->template($organization))
            ->create(['rule_key' => 'ordinary-booking-without-mini-app']);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'booking-event-without-mini-app', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        $feedbackRule = ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->where('rule_key', 'booking-completed-feedback-en')
            ->sole();
        $feedbackAction = ScenarioAction::query()
            ->where('organization_id', $organization->getKey())
            ->where('scenario_event_id', $event->getKey())
            ->where('scenario_rule_id', $feedbackRule->getKey())
            ->sole();
        $ordinaryAction = ScenarioAction::query()
            ->where('organization_id', $organization->getKey())
            ->where('scenario_event_id', $event->getKey())
            ->where('scenario_rule_id', $ordinaryRule->getKey())
            ->sole();
        self::assertSame(FeedbackMiniAppConfigurationException::ERROR_CODE, $feedbackAction->render_context['feedback']['configuration_error']);

        foreach ([$feedbackAction, $ordinaryAction] as $action) {
            $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
            $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
        }

        app(ExecuteScenarioAction::class)->handle($feedbackAction->getKey());
        app(ExecuteScenarioAction::class)->handle($ordinaryAction->getKey());

        self::assertSame(ScenarioDeliveryStatus::Unavailable, $feedbackAction->deliveries()->sole()->status);
        self::assertSame(FeedbackMiniAppConfigurationException::ERROR_CODE, $feedbackAction->deliveries()->sole()->last_error_code);
        self::assertSame(ScenarioActionStatus::Suppressed, $feedbackAction->fresh()->status);
        self::assertSame(ScenarioActionStatus::Delivered, $ordinaryAction->fresh()->status);
        self::assertCount(1, $this->channel->messages);
        self::assertSame('Hello '.$client->full_name.'.', $this->channel->messages[0]->body);
    }

    public function test_feedback_materialization_rejects_missing_http_and_malformed_mini_app_urls_without_calling_provider(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        app(ScenarioNotificationSeeder::class)->run();
        $feedbackRule = ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->where('rule_key', 'booking-completed-feedback-en')
            ->sole();

        foreach ([null, 'http://mini.example.test', 'not-a-url'] as $index => $portalUrl) {
            config()->set('portal.telegram.portal_url', $portalUrl);
            $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
            $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'invalid-feedback-url-'.$index, CarbonImmutable::now());
            app(MaterializeScenarioEvent::class)->handle($event->getKey());
            $action = ScenarioAction::query()
                ->where('organization_id', $organization->getKey())
                ->where('scenario_event_id', $event->getKey())
                ->where('scenario_rule_id', $feedbackRule->getKey())
                ->sole();
            $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
            $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

            app(ExecuteScenarioAction::class)->handle($action->getKey());

            self::assertSame(ScenarioDeliveryStatus::Unavailable, $action->deliveries()->sole()->status);
            self::assertSame(FeedbackMiniAppConfigurationException::ERROR_CODE, $action->deliveries()->sole()->last_error_code);
        }

        self::assertCount(0, $this->channel->messages);
    }

    public function test_template_updates_create_an_immutable_new_version(): void
    {
        [$organization, $admin] = array_slice($this->fixture(), 0, 2);
        app(OrganizationContext::class)->set($organization);
        $template = app(CreateNotificationTemplate::class)->handle($admin, [
            'template_key' => 'versioned-template',
            'name' => 'Versioned template',
            'locale' => 'en',
            'purpose' => 'service',
            'is_active' => true,
            'body' => 'Initial {{ client.full_name }}.',
            'variables' => ['client.full_name'],
        ]);

        app(UpdateNotificationTemplate::class)->handle($admin, $template, [
            'template_key' => 'versioned-template',
            'name' => 'Versioned template updated',
            'locale' => 'en',
            'purpose' => 'service',
            'is_active' => true,
            'body' => 'Updated {{ client.full_name }}.',
            'variables' => ['client.full_name'],
        ]);

        self::assertSame(2, $template->versions()->count());
        self::assertSame('Updated {{ client.full_name }}.', $template->versions()->latest('version')->firstOrFail()->body);
        self::assertSame(1, DB::table('audit_events')->where('action', 'scenario.template.created')->count());
        self::assertSame(1, DB::table('audit_events')->where('action', 'scenario.template.updated')->count());
    }

    public function test_scheduler_dispatches_due_events_and_actions_without_making_queue_state_authoritative(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $templateVersion = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($templateVersion)->create();
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'scheduler-event', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
        $pendingEvent = ScenarioEvent::factory()->forOrganization($organization)->create([
            'idempotency_key' => 'scheduler-pending-event',
            'available_at' => now()->subSecond(),
        ]);
        Queue::fake();

        $result = app(ScheduleScenarioWork::class)->handle();

        self::assertSame(1, $result['events']);
        self::assertSame(1, $result['actions']);
        Queue::assertPushed(ProcessScenarioEvent::class, fn (ProcessScenarioEvent $job): bool => $job->scenarioEventId === $pendingEvent->id);
        Queue::assertPushed(ExecuteScenarioActionJob::class, fn (ExecuteScenarioActionJob $job): bool => $job->scenarioActionId === $action->id);
        self::assertSame(ScenarioActionStatus::Scheduled, $action->fresh()->status);
    }

    public function test_disabled_rules_do_not_materialize_and_current_state_change_suppresses_action(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        $templateVersion = $this->template($organization);
        $disabledRule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($templateVersion)->create([
            'is_enabled' => false,
            'rule_key' => 'disabled-rule',
        ]);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'booking-event-2', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->id);
        self::assertSame(0, ScenarioAction::query()->where('scenario_rule_id', $disabledRule->id)->count());

        $secondBooking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $activeRule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($templateVersion)->create([
            'rule_key' => 'state-sensitive-rule',
            'conditions' => [['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed']],
        ]);
        $eventTwo = app(RecordScenarioEvent::class)->bookingCompleted($secondBooking, 'booking-event-3', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($eventTwo->id);
        $action = ScenarioAction::query()->where('scenario_rule_id', $activeRule->id)->sole();
        $secondBooking->forceFill(['status' => BookingStatus::Cancelled])->save();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertSame(ScenarioDeliveryStatus::Suppressed, $action->deliveries()->sole()->status);
        self::assertSame('current_conditions_not_met', $action->fresh()->terminal_reason);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_unavailable_channel_falls_back_in_configured_priority_order(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        ClientChannelIdentity::factory()->forClient($client)->create([
            'channel' => 'fallback',
            'external_id' => (string) $client->id.'-fallback',
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
        $primary = new RecordingNotificationChannel('telegram', NotificationDeliveryResult::unavailable('offline'));
        $fallback = new RecordingNotificationChannel('fallback');
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$primary, $fallback]));
        $templateVersion = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($templateVersion)->create([
            'channel_priority' => ['telegram', 'fallback'],
        ]);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'booking-event-fallback', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame(ScenarioDeliveryStatus::Unavailable, $action->deliveries()->where('channel', 'telegram')->sole()->status);
        self::assertSame(ScenarioDeliveryStatus::Delivered, $action->deliveries()->where('channel', 'fallback')->sole()->status);
        self::assertCount(1, $primary->messages);
        self::assertCount(1, $fallback->messages);
    }

    public function test_suppressed_delivery_closes_the_action_without_fallback(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedTelegramIdentity($organization, $client);
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([
            new RecordingNotificationChannel(
                'telegram',
                new NotificationDeliveryResult(NotificationDeliveryOutcome::Suppressed, errorCode: 'client_suppressed'),
            ),
        ]));
        $templateVersion = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($templateVersion)->create();
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'booking-event-suppressed', CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->getKey())->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($action->getKey());

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertSame(ScenarioDeliveryStatus::Suppressed, $action->deliveries()->sole()->status);
        self::assertSame('provider_suppressed', $action->fresh()->terminal_reason);
        self::assertSame('suppressed', $action->deliveries()->sole()->attempts()->sole()->outcome->value);
    }

    public function test_internal_recipient_strategy_resolves_only_active_same_organization_members(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        OrganizationChannelIdentity::factory()->forUser($staff)->verified()->create();
        $templateVersion = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($templateVersion)->create([
            'rule_key' => 'internal-member-rule',
            'recipient_strategy' => ['type' => 'members', 'user_ids' => [$staff->id]],
        ]);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'booking-event-internal', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($event->id);

        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->sole();
        self::assertSame('internal', $action->recipient_type);
        self::assertSame($staff->id, $action->recipient_user_id);
        self::assertNull($action->client_id);
    }

    public function test_disabled_specialist_notifications_suppress_already_materialized_internal_actions(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        app(OrganizationContext::class)->set($organization);
        $specialist = app(UpdateSpecialist::class)->handle(
            actor: $admin,
            specialist: $specialist,
            displayName: $specialist->display_name,
            isActive: true,
            timezone: $specialist->timezone,
            staffUserId: $staff->id,
            notificationSettings: SpecialistNotificationSettings::from('555000111', false),
        );
        $templateVersion = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($templateVersion)->create([
            'trigger_event' => 'booking.completed',
            'delay_value' => 0,
            'delay_unit' => 'minutes',
            'recipient_strategy' => ['type' => 'members', 'user_ids' => [$staff->id]],
        ]);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'booking-event-disabled-specialist', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->sole();

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame([], $this->channel->messages);
        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertSame('no_available_channel', $action->fresh()->terminal_reason);
        self::assertSame(ScenarioDeliveryStatus::Unavailable, $action->deliveries()->sole()->status);
    }

    public function test_scenario_actions_and_crm_queries_are_organization_scoped(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $otherTemplateVersion = $this->template($otherOrganization);
        $otherRule = ScenarioRule::factory()->forOrganization($otherOrganization)->usingTemplate($otherTemplateVersion)->create();
        $this->setFilamentContext($admin, $organization);

        $this->get(route('filament.admin.resources.scenario-rules.index'))->assertOk();
        $this->get(route('filament.admin.resources.scenario-rules.edit', ['record' => $otherRule]))->assertNotFound();

        $this->expectException(AuthorizationException::class);
        app(UpdateScenarioRule::class)->handle($admin, $otherRule, [
            'rule_key' => $otherRule->rule_key,
            'name' => $otherRule->name,
            'trigger_event' => 'booking.completed',
            'is_enabled' => true,
            'delay_value' => 1,
            'delay_unit' => 'hours',
            'purpose' => 'service',
            'conditions' => [],
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
            'template_version_id' => $otherTemplateVersion->id,
        ]);
    }

    public function test_admin_can_open_scenario_rule_configuration_while_staff_cannot_access_admin_panel(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $this->setFilamentContext($admin, $organization);

        $this->actingAs($admin)->get(route('filament.admin.resources.scenario-rules.index'))->assertOk();
        $this->actingAs($staff)->get('/admin')->assertForbidden();
        self::assertTrue(ScenarioRuleResource::canAccess());
    }

    public function test_crm_can_create_and_version_a_scenario_rule_through_application_actions(): void
    {
        [$organization, $admin] = array_slice($this->fixture(), 0, 2);
        $templateVersion = $this->template($organization);
        $this->setFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(CreateScenarioRule::class)
            ->fillForm([
                'rule_key' => 'crm-follow-up',
                'name' => 'CRM follow-up',
                'trigger_event' => 'booking.completed',
                'is_enabled' => true,
                'delay_value' => 24,
                'delay_unit' => 'hours',
                'purpose' => 'service',
                'conditions' => [],
                'recipient_strategy' => ['type' => 'client'],
                'channel_priority' => ['telegram'],
                'template_version_id' => $templateVersion->id,
            ])
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $rule = ScenarioRule::query()->sole();
        self::assertSame(24, $rule->delay_value);
        self::assertSame(1, $rule->version);
        self::assertSame(1, DB::table('audit_events')->where('action', 'scenario.rule.created')->count());

        Livewire::actingAs($admin)
            ->test(EditScenarioRule::class, ['record' => $rule->getRouteKey()])
            ->fillForm([
                'rule_key' => $rule->rule_key,
                'name' => 'CRM follow-up updated',
                'trigger_event' => 'booking.completed',
                'is_enabled' => true,
                'delay_value' => 48,
                'delay_unit' => 'hours',
                'purpose' => 'service',
                'conditions' => [],
                'recipient_strategy' => ['type' => 'client'],
                'channel_priority' => ['telegram'],
                'template_version_id' => $templateVersion->id,
            ])
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(48, $rule->fresh()->delay_value);
        self::assertSame(2, $rule->fresh()->version);
    }

    /** @return array{Organization, User, Client, Specialist, Service} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'en', 'timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create();

        return [$organization, $admin, $client, $specialist, $service];
    }

    private function template(Organization $organization): NotificationTemplateVersion
    {
        $template = NotificationTemplate::factory()->forOrganization($organization)->create([
            'template_key' => 'follow-up-'.uniqid(),
        ]);

        return NotificationTemplateVersion::factory()->forTemplate($template)->create([
            'body' => 'Hello {{ client.full_name }}.',
            'variables' => ['client.full_name'],
        ]);
    }

    private function verifiedTelegramIdentity(Organization $organization, Client $client): void
    {
        ClientChannelIdentity::factory()->forClient($client)->create([
            'channel' => 'telegram',
            'external_id' => (string) $client->id.'-chat',
            'external_username' => 'client_'.$client->id,
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
    }

    private function booking(
        Organization $organization,
        Client $client,
        Specialist $specialist,
        Service $service,
        BookingStatus $status,
    ): Booking {
        $start = CarbonImmutable::now()->subHours(3);

        return Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => $status->value,
                'starts_at' => $start,
                'ends_at' => $start->addHour(),
                'blocking_ends_at' => $start->addHour(),
                'schedule_timezone' => 'UTC',
            ]);
    }

    private function makeDue(ScenarioAction $action): void
    {
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
    }

    private function setFilamentContext(User $admin, Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->id);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(OrganizationContext::class)->set($organization);
    }
}
