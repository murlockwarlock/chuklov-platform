<?php

namespace Tests\Feature;

use App\Filament\Resources\ScenarioRules\Pages\CreateScenarioRule;
use App\Filament\Resources\ScenarioRules\Pages\EditScenarioRule;
use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
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
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
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

    private function setFilamentContext(User $admin, Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->id);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(OrganizationContext::class)->set($organization);
    }
}
