<?php

namespace Tests\Integration;

use App\Filament\Livewire\DatabaseNotifications;
use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationSeverity;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Channels\Infrastructure\Database\DatabaseNotificationChannel;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Actions\ReplyToCompanion;
use App\Modules\ClientCompanion\Application\Actions\TakeOverCompanionConversation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scheduling\Domain\Enums\BookingSource;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CrmNotificationCenterPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_persists_one_permission_scoped_notification_for_a_replayed_event(): void
    {
        $this->requirePostgres();
        [$organization, $administrator, $staff, $foreignAdministrator, $client, $specialist, $service] = $this->fixture();
        app(OrganizationContext::class)->set($organization);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);

        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::PendingReview->value,
                'source' => BookingSource::Portal->value,
                'visit_format' => VisitFormat::HomeVisit->value,
                'starts_at' => CarbonImmutable::now()->addDay(),
                'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
                'blocking_ends_at' => CarbonImmutable::now()->addDay()->addHour(),
                'schedule_timezone' => 'UTC',
                'client_timezone' => 'UTC',
            ]);
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'postgres-crm-center', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        $action = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->where('recipient_user_id', $administrator->getKey())
            ->whereJsonContains('channel_priority', 'database')
            ->sole();
        self::assertSame(1, ScenarioAction::query()->where('scenario_event_id', $event->getKey())->count());
        self::assertSame(0, $staff->notifications()->count());
        self::assertSame(0, $foreignAdministrator->notifications()->count());

        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([
            app(DatabaseNotificationChannel::class),
        ]));

        app(ExecuteScenarioAction::class)->handle($action->getKey());
        app(ExecuteScenarioAction::class)->handle($action->getKey());

        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame(1, $administrator->fresh()->notifications()->count());
        self::assertSame(NotificationSeverity::High->value, $administrator->fresh()->notifications()->sole()->data['severity']);
        $this->actingAs($administrator);
        self::assertSame(1, app(DatabaseNotifications::class)->getNotificationsQuery()->count());
        self::assertSame(1, DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $administrator->getKey())
            ->count());
    }

    public function test_postgresql_uses_membership_preference_for_assigned_specialist_bell_delivery(): void
    {
        $this->requirePostgres();
        [$organization, , $staff, $foreignAdministrator, $client, $specialist, $service] = $this->fixture();
        $specialist->forceFill(['notifications_enabled' => false])->save();
        Specialist::factory()->forOrganization($organization)->create([
            'staff_user_id' => $staff->getKey(),
            'is_active' => false,
            'notifications_enabled' => false,
        ]);
        app(OrganizationContext::class)->set($organization);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([
            app(DatabaseNotificationChannel::class),
        ]));

        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Requested->value,
                'source' => BookingSource::Portal->value,
                'visit_format' => VisitFormat::Office->value,
                'starts_at' => CarbonImmutable::now()->addDay(),
                'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
                'blocking_ends_at' => CarbonImmutable::now()->addDay()->addHour(),
                'schedule_timezone' => 'UTC',
                'client_timezone' => 'UTC',
            ]);
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'postgres-specialist-preference', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        $action = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->where('recipient_user_id', $staff->getKey())
            ->whereJsonContains('channel_priority', 'database')
            ->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($action->getKey());
        app(ExecuteScenarioAction::class)->handle($action->getKey());

        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame(1, $staff->fresh()->notifications()->count());
        self::assertSame(0, $foreignAdministrator->fresh()->notifications()->count());
    }

    public function test_postgresql_persists_one_notification_for_a_replayed_handoff_message_and_none_for_staff_reply(): void
    {
        $this->requirePostgres();
        [$organization, $administrator, , , $client] = $this->fixture();
        app(OrganizationContext::class)->set($organization);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        Queue::fake();

        app(AcceptCompanionMessage::class)->handle(
            client: $client,
            channel: 'telegram',
            body: 'Первое сообщение клиента',
            idempotencyKey: null,
            originExternalId: 'postgres-handoff-initial',
            transportChatId: 'postgres-chat',
            locale: 'ru',
        );
        app(TakeOverCompanionConversation::class)->handle($administrator, $client);
        $inbound = app(AcceptCompanionMessage::class)->handle(
            client: $client,
            channel: 'telegram',
            body: 'Новое сообщение после подключения специалиста',
            idempotencyKey: null,
            originExternalId: 'postgres-handoff-inbound',
            transportChatId: 'postgres-chat',
            locale: 'ru',
        );
        $event = ScenarioEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event_name', ScenarioEventType::CompanionSpecialistAttention->value)
            ->whereJsonContains('payload->message_id', $inbound->inbound_message_id)
            ->sole();

        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->where('recipient_user_id', $administrator->getKey())
            ->whereJsonContains('channel_priority', 'database')
            ->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([
            app(DatabaseNotificationChannel::class),
        ]));

        app(ExecuteScenarioAction::class)->handle($action->getKey());
        app(ExecuteScenarioAction::class)->handle($action->getKey());
        app(AcceptCompanionMessage::class)->handle(
            client: $client,
            channel: 'telegram',
            body: 'Новое сообщение после подключения специалиста',
            idempotencyKey: null,
            originExternalId: 'postgres-handoff-inbound',
            transportChatId: 'postgres-chat',
            locale: 'ru',
        );
        app(ReplyToCompanion::class)->handle($administrator, $client, 'Ответ специалиста');

        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame(1, ScenarioEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event_name', ScenarioEventType::CompanionSpecialistAttention->value)
            ->count());
        self::assertSame(1, $administrator->fresh()->notifications()->count());
        self::assertSame(
            url('/admin/messages?client='.$client->getKey()),
            $administrator->fresh()->notifications()->sole()->data['actions'][0]['url'],
        );
    }

    public function test_postgresql_unread_total_remains_authoritative_across_paginated_pages_and_read_actions(): void
    {
        $this->requirePostgres();
        [$organization, $administrator, , $foreignAdministrator] = $this->fixture();
        $foreignOrganizationId = $foreignAdministrator->memberships()->value('organization_id');
        $foreignOrganization = Organization::query()->findOrFail($foreignOrganizationId);
        app(OrganizationContext::class)->set($organization);
        $channel = app(DatabaseNotificationChannel::class);

        for ($index = 0; $index < 51; $index++) {
            $channel->send(new NotificationMessage(
                recipientExternalId: (string) $administrator->getKey(),
                body: 'PostgreSQL уведомление '.$index,
                subject: 'PostgreSQL уведомление '.$index,
                locale: 'ru',
                idempotencyKey: 'postgres-page-count-'.$index,
                organizationId: $organization->getKey(),
                severity: NotificationSeverity::Action,
            ));
        }

        $channel->send(new NotificationMessage(
            recipientExternalId: (string) $administrator->getKey(),
            body: 'Foreign PostgreSQL уведомление',
            subject: 'Foreign PostgreSQL уведомление',
            locale: 'ru',
            idempotencyKey: 'postgres-page-count-foreign',
            organizationId: $foreignOrganization->getKey(),
            severity: NotificationSeverity::Action,
        ));

        $this->actingAs($administrator);
        $bell = app(DatabaseNotifications::class);
        self::assertSame(51, $bell->getUnreadNotificationsCount());
        self::assertSame(50, $bell->getNotifications()->count());

        $firstNotification = $bell->getNotifications()->first();
        self::assertNotNull($firstNotification);
        $bell->markNotificationAsRead((string) $firstNotification->getKey());
        self::assertSame(50, $bell->getUnreadNotificationsCount());

        $bell->markAllNotificationsAsRead();
        self::assertSame(0, $bell->getUnreadNotificationsCount());
        self::assertSame(1, $administrator->fresh()->notifications()->whereNull('read_at')->count());
    }

    /** @return array{Organization, User, User, User, Client, Specialist, Service} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignOrganization = Organization::factory()->create(['timezone' => 'Europe/Berlin']);
        $administrator = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $foreignAdministrator = User::factory()->forOrganization($foreignOrganization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'ru', 'timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create([
            'staff_user_id' => $staff->getKey(),
            'timezone' => 'UTC',
        ]);
        $service = Service::factory()->forOrganization($organization)->create();

        return [$organization, $administrator, $staff, $foreignAdministrator, $client, $specialist, $service];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('CRM notification persistence requires the isolated PostgreSQL test environment.');
        }
    }
}
