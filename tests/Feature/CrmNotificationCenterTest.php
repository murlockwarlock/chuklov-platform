<?php

namespace Tests\Feature;

use App\Filament\Livewire\DatabaseNotifications;
use App\Models\User;
use App\Modules\Channels\Domain\Enums\NotificationSeverity;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Channels\Infrastructure\Database\DatabaseNotificationChannel;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Application\AppointmentReminderScheduler;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Application\UpdateScenarioRule;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CrmNotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_booking_projects_once_to_the_assigned_specialists_crm_bell(): void
    {
        [$organization, $staff, $client, $specialist, $service] = $this->fixture();
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Requested);
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'crm-center-booking-created', now()->toImmutable());

        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        $action = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->where('recipient_user_id', $staff->getKey())
            ->whereJsonContains('channel_priority', 'database')
            ->sole();
        $action->forceFill([
            'scheduled_for' => now()->subSecond(),
        ])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($action->getKey());
        app(ExecuteScenarioAction::class)->handle($action->getKey());

        $notification = $staff->fresh()->notifications()->sole();
        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame('Новая запись', $notification->data['title']);
        self::assertSame(NotificationSeverity::High->value, $notification->data['severity']);
        self::assertStringContainsString($client->full_name, $notification->data['body']);
        self::assertSame(
            url('/admin/bookings/'.$booking->getKey()),
            $notification->data['actions'][0]['url'],
        );
    }

    public function test_home_visit_review_requires_scheduling_permission_and_preserves_tenant_and_preference_boundaries(): void
    {
        [$organization, $staff, $client, $specialist, $service] = $this->fixture();
        $administrator = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $foreignOrganization = Organization::factory()->create();
        $foreignAdministrator = User::factory()->forOrganization($foreignOrganization, OrganizationRole::Administrator)->create();
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        app(EnsureOperationalNotificationDefaults::class)->handle($foreignOrganization);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::PendingReview);
        $booking->forceFill([
            'visit_format' => VisitFormat::HomeVisit->value,
            'location' => 'Адрес клиента',
        ])->save();
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'crm-center-home-visit', now()->toImmutable());

        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        $action = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->whereJsonContains('channel_priority', 'database')
            ->sole();
        self::assertSame($administrator->getKey(), $action->recipient_user_id);
        self::assertNotSame($staff->getKey(), $action->recipient_user_id);
        self::assertSame(0, $foreignAdministrator->notifications()->count());

        $administrator->membershipFor($organization)->forceFill(['notifications_enabled' => false])->save();
        $secondBooking = $this->booking($organization, $client, $specialist, $service, BookingStatus::PendingReview);
        $secondBooking->forceFill(['visit_format' => VisitFormat::HomeVisit->value])->save();
        $secondEvent = app(RecordScenarioEvent::class)->bookingCreated($secondBooking, 'crm-center-home-visit-preference', now()->toImmutable());
        app(MaterializeScenarioEvent::class)->handle($secondEvent->getKey());

        self::assertSame(0, ScenarioAction::query()->where('scenario_event_id', $secondEvent->getKey())->count());
    }

    public function test_appointment_reminder_uses_the_existing_reminder_occurrence_for_the_crm_bell(): void
    {
        [$organization, $staff, $client, $specialist, $service] = $this->fixture();
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Confirmed);
        $booking->forceFill([
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(4),
            'blocking_ends_at' => now()->addHours(4),
        ])->save();
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'crm-center-reminder', now()->toImmutable());

        app(AppointmentReminderScheduler::class)->schedule($booking, $event);

        $action = ScenarioAction::query()
            ->where('booking_id', $booking->getKey())
            ->where('recipient_user_id', $staff->getKey())
            ->whereJsonContains('channel_priority', 'database')
            ->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($action->getKey());

        $notification = $staff->fresh()->notifications()->sole();
        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame(NotificationSeverity::Action->value, $notification->data['severity']);
        self::assertStringContainsString($client->full_name, $notification->data['body']);
        self::assertSame(
            url('/admin/bookings/'.$booking->getKey()),
            $notification->data['actions'][0]['url'],
        );
    }

    public function test_operational_defaults_cover_existing_actionable_event_sources(): void
    {
        $organization = Organization::factory()->create();
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);

        $ruleKeys = [
            'booking-created-specialist-database',
            'booking-home-visit-review-database',
            'booking-confirmed-specialist-database',
            'booking-rescheduled-specialist-database',
            'booking-cancelled-specialist-database',
            'companion-fallback-failed-database',
            'companion-handoff-database',
            'referral-payout-request-database',
            'referral-payout-status-database',
            'survey-completed-database',
            'survey-stagnation-database',
            'b2b-lead-submitted-database',
            'b2b-sales-call-ready-database',
            'knowledge-ingestion-failed-database',
        ];

        foreach ($ruleKeys as $ruleKey) {
            $rule = ScenarioRule::query()
                ->where('organization_id', $organization->getKey())
                ->where('rule_key', $ruleKey)
                ->sole();
            self::assertSame(['database'], $rule->channel_priority);
            self::assertNotEmpty($rule->recipient_strategy);
        }
    }

    public function test_knowledge_ingestion_failure_reaches_staff_with_knowledge_access_and_material_action(): void
    {
        [$organization, $staff] = $this->fixture();
        $administrator = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        $source = KnowledgeSource::query()->create([
            'organization_id' => $organization->getKey(),
            'type' => 'authored_text',
            'title' => 'Правила записи',
            'status' => 'active',
            'client_companion_enabled' => false,
        ]);
        $revision = KnowledgeRevision::query()->create([
            'organization_id' => $organization->getKey(),
            'knowledge_source_id' => $source->getKey(),
            'version' => 1,
            'status' => 'failed',
            'content' => 'Текст материала',
            'mime_type' => 'text/markdown',
            'size_bytes' => 16,
            'content_checksum' => hash('sha256', 'Текст материала'),
        ]);
        $run = KnowledgeIngestionRun::query()->create([
            'organization_id' => $organization->getKey(),
            'knowledge_source_id' => $source->getKey(),
            'knowledge_revision_id' => $revision->getKey(),
            'configuration_key' => 'test-knowledge-config',
            'status' => 'failed',
            'chunk_strategy' => 'paragraphs',
            'chunk_version' => 'test-v1',
            'chunk_target_characters' => 1000,
            'chunk_maximum_characters' => 2000,
            'chunk_overlap_characters' => 100,
            'embedding_provider' => 'test',
            'embedding_model' => 'test-model',
            'embedding_dimensions' => 3,
            'embedding_configuration_version' => 'test-v1',
            'attempts' => 1,
            'error_code' => 'embedding_or_persistence_failed',
            'completed_at' => now(),
        ]);
        $event = app(RecordScenarioEvent::class)->knowledgeIngestionFailed(
            $source,
            $revision,
            $run,
            'embedding_or_persistence_failed',
            now()->toImmutable(),
        );

        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        $actions = ScenarioAction::query()->where('scenario_event_id', $event->getKey())->get();
        self::assertCount(2, $actions);
        $administratorAction = $actions->firstWhere('recipient_user_id', $administrator->getKey());
        self::assertNotNull($administratorAction);
        self::assertSame($staff->getKey(), $actions->firstWhere('recipient_user_id', $staff->getKey())?->recipient_user_id);
        self::assertSame(
            url('/admin/knowledge-sources/'.$source->getKey().'/edit'),
            $administratorAction->render_context['knowledge']['crm_url'],
        );
        $administratorAction->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $administratorAction->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($administratorAction->getKey());

        $notification = $administrator->fresh()->notifications()->sole();
        self::assertSame('Ошибка обработки материала', $notification->data['title']);
        self::assertStringContainsString('Правила записи', $notification->data['body']);
        self::assertSame(
            url('/admin/knowledge-sources/'.$source->getKey().'/edit'),
            $notification->data['actions'][0]['url'],
        );
    }

    public function test_editing_an_operational_rule_preserves_its_event_permission(): void
    {
        [$organization] = $this->fixture();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        $rule = ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->where('rule_key', 'booking-home-visit-review-database')
            ->sole();

        $updated = app(UpdateScenarioRule::class)->handle($admin, $rule, [
            'rule_key' => $rule->rule_key,
            'name' => $rule->name,
            'trigger_event' => $rule->trigger_event->value,
            'is_enabled' => $rule->is_enabled,
            'delay_value' => $rule->delay_value,
            'delay_unit' => $rule->delay_unit->value,
            'purpose' => $rule->purpose->value,
            'conditions' => $rule->conditions,
            'recipient_strategy' => ['type' => 'roles', 'roles' => ['staff']],
            'channel_priority' => $rule->channel_priority,
            'template_version_id' => $rule->template_version_id,
            'max_occurrences' => $rule->max_occurrences,
            'repeat_interval_value' => $rule->repeat_interval_value,
            'repeat_interval_unit' => $rule->repeat_interval_unit?->value,
        ]);

        self::assertSame('manage_scheduling', $updated->recipient_strategy['permission']);

        app(EnsureOperationalNotificationDefaults::class)->handle($organization);

        self::assertSame(['type' => 'roles', 'roles' => ['staff'], 'permission' => 'manage_scheduling'], ScenarioRule::query()->findOrFail($updated->getKey())->recipient_strategy);
    }

    public function test_database_bell_and_read_actions_are_scoped_to_the_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $user = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        OrganizationMembership::factory()->forOrganization($foreignOrganization)->forUser($user)->create([
            'role' => OrganizationRole::Administrator->value,
        ]);
        app(OrganizationContext::class)->set($organization);
        $channel = app(DatabaseNotificationChannel::class);

        foreach ([$organization, $foreignOrganization] as $index => $notificationOrganization) {
            $channel->send(new NotificationMessage(
                recipientExternalId: (string) $user->getKey(),
                body: 'Оперативное уведомление '.$index,
                subject: 'Уведомление '.$index,
                locale: 'ru',
                idempotencyKey: 'organization-scope-'.$index,
                organizationId: $notificationOrganization->getKey(),
                severity: NotificationSeverity::Action,
            ));
        }

        $this->actingAs($user);
        $bell = app(DatabaseNotifications::class);

        self::assertSame(1, $bell->getNotificationsQuery()->count());
        $bell->markAllNotificationsAsRead();
        self::assertSame(1, $user->fresh()->notifications()->whereNotNull('read_at')->count());
        self::assertSame(1, $user->fresh()->notifications()->whereNull('read_at')->count());
    }

    public function test_database_projection_is_idempotent_per_recipient(): void
    {
        $organization = Organization::factory()->create();
        $firstUser = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $secondUser = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $channel = app(DatabaseNotificationChannel::class);

        foreach ([$firstUser, $secondUser] as $user) {
            $message = new NotificationMessage(
                recipientExternalId: (string) $user->getKey(),
                body: 'Новая задача',
                subject: 'Новая задача',
                locale: 'ru',
                idempotencyKey: 'same-operational-event',
                organizationId: $organization->getKey(),
                severity: NotificationSeverity::Action,
            );
            $channel->send($message);
            $channel->send($message);
        }

        self::assertSame(1, $firstUser->fresh()->notifications()->count());
        self::assertSame(1, $secondUser->fresh()->notifications()->count());
    }

    /** @return array{Organization, User, Client, Specialist, Service} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'ru']);
        $specialist = Specialist::factory()->forOrganization($organization)->create([
            'staff_user_id' => $staff->getKey(),
            'timezone' => 'UTC',
        ]);
        $service = Service::factory()->forOrganization($organization)->create();

        return [$organization, $staff, $client, $specialist, $service];
    }

    private function booking(
        Organization $organization,
        Client $client,
        Specialist $specialist,
        Service $service,
        BookingStatus $status,
    ): Booking {
        $start = now()->addDays(2)->setTime(10, 0);

        return Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => $status->value,
                'visit_format' => VisitFormat::Office->value,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addHour(),
                'blocking_ends_at' => $start->copy()->addHour(),
                'schedule_timezone' => 'UTC',
            ]);
    }
}
