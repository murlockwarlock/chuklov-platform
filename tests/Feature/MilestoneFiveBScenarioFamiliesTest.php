<?php

namespace Tests\Feature;

use App\Filament\Resources\ScenarioRules\Pages\CreateScenarioRule as CreateScenarioRulePage;
use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\ClientPortal\Application\StartClientOnboarding;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\CreateScenarioRule;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Database\Seeders\ScenarioNotificationSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class MilestoneFiveBScenarioFamiliesTest extends TestCase
{
    use RefreshDatabase;

    private RecordingNotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$this->channel]));
    }

    public function test_post_session_family_uses_configured_delay_and_repeat_snapshot(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedClient($client);
        $version = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 24,
            'delay_unit' => 'hours',
            'max_occurrences' => 3,
            'repeat_interval_value' => 12,
            'repeat_interval_unit' => 'hours',
            'conditions' => [['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed']],
        ]);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $occurredAt = CarbonImmutable::now()->subDays(5)->setMinute(0)->setSecond(0);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'm5b-post-session', $occurredAt);

        app(MaterializeScenarioEvent::class)->handle($event->id);

        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->sole();
        self::assertSame($occurredAt->addDay()->toIso8601String(), $action->scheduled_for->toIso8601String());
        self::assertSame(1, $action->sequence_number);
        self::assertSame(3, $action->max_occurrences);
        self::assertSame($version->id, $action->template_version_id);
        self::assertSame($rule->conditions, $action->condition_snapshot);

        $this->makeDue($action);
        app(ExecuteScenarioAction::class)->handle($action->id);

        $next = ScenarioAction::query()
            ->where('scenario_rule_id', $rule->id)
            ->where('sequence_number', 2)
            ->sole();
        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame($version->id, $next->template_version_id);
        self::assertSame($action->condition_snapshot, $next->condition_snapshot);
        self::assertSame(2, $next->sequence_number);

        $this->makeDue($next);
        app(ExecuteScenarioAction::class)->handle($next->id);
        $third = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->where('sequence_number', 3)->sole();
        $this->makeDue($third);
        app(ExecuteScenarioAction::class)->handle($third->id);

        self::assertSame(3, ScenarioAction::query()->where('scenario_rule_id', $rule->id)->count());
        self::assertCount(3, $this->channel->messages);
    }

    public function test_post_session_conditional_72_hour_rule_is_typed_and_not_bespoke(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $version = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 72,
            'delay_unit' => 'hours',
            'conditions' => [
                ['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed'],
                ['type' => 'client.language', 'operator' => 'equals', 'value' => 'ru'],
            ],
        ]);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $client->forceFill(['language' => 'en'])->save();
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'm5b-72h', CarbonImmutable::now()->subDays(4));

        app(MaterializeScenarioEvent::class)->handle($event->id);

        self::assertSame(0, ScenarioAction::query()->where('scenario_rule_id', $rule->id)->count());
        self::assertSame('client.language', $rule->conditions[1]['type']);
    }

    public function test_disabled_rule_stops_a_scheduled_repeat_family(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedClient($client);
        $version = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'max_occurrences' => 2,
            'repeat_interval_value' => 1,
            'repeat_interval_unit' => 'days',
        ]);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'm5b-disabled-repeat', CarbonImmutable::now()->subDay());
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->sole();
        $rule->forceFill(['is_enabled' => false])->save();
        $this->makeDue($action);

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertSame(1, ScenarioAction::query()->where('scenario_rule_id', $rule->id)->count());
    }

    public function test_retention_rule_uses_configured_window_and_rechecks_a_new_booking(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->verifiedClient($client);
        $version = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 3,
            'delay_unit' => 'days',
            'conditions' => [['type' => 'booking.has_qualifying_next_booking', 'operator' => 'equals', 'value' => false]],
        ]);
        $completed = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $occurredAt = CarbonImmutable::now()->subDays(5);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($completed, 'm5b-retention', $occurredAt);
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->sole();

        $this->makeDue($action);
        $this->futureBooking($organization, $client, $specialist, $service, BookingStatus::Confirmed, $occurredAt->addDay());
        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertSame('current_conditions_not_met', $action->fresh()->terminal_reason);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_retention_does_not_count_cancelled_or_pending_review_booking(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $version = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 3,
            'delay_unit' => 'days',
            'conditions' => [['type' => 'booking.has_qualifying_next_booking', 'operator' => 'equals', 'value' => false]],
        ]);
        $completed = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $occurredAt = CarbonImmutable::now()->subDays(5);
        $this->futureBooking($organization, $client, $specialist, $service, BookingStatus::Cancelled, $occurredAt->addDay());
        $this->futureBooking($organization, $client, $specialist, $service, BookingStatus::PendingReview, $occurredAt->addDays(2));
        $event = app(RecordScenarioEvent::class)->bookingCompleted($completed, 'm5b-retention-terminal', $occurredAt);

        app(MaterializeScenarioEvent::class)->handle($event->id);

        self::assertSame(1, ScenarioAction::query()->where('scenario_rule_id', $rule->id)->count());
    }

    public function test_onboarding_reengagement_is_internal_state_only_and_completion_suppresses_future_send(): void
    {
        [$organization, , $client] = $this->fixture();
        $this->verifiedClient($client);
        app(OrganizationContext::class)->set($organization);
        $version = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'trigger_event' => 'onboarding.started',
            'delay_value' => 1,
            'delay_unit' => 'days',
            'conditions' => [['type' => 'onboarding.completed', 'operator' => 'equals', 'value' => false]],
        ]);

        $onboarding = app(StartClientOnboarding::class)->handle($client);
        $event = ScenarioEvent::query()->where('event_name', 'onboarding.started')->sole();
        self::assertSame($onboarding->id, $event->payload['onboarding_id']);
        self::assertSame(1, ScenarioEvent::query()->where('event_name', 'onboarding.started')->count());

        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->sole();
        $onboarding->forceFill(['completed_at' => now()])->save();
        $this->makeDue($action);
        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertSame('current_conditions_not_met', $action->fresh()->terminal_reason);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_marketing_consent_condition_is_explicit_and_missing_consent_fails_closed(): void
    {
        [$organization, , $client] = $this->fixture();
        app(OrganizationContext::class)->set($organization);
        $version = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'trigger_event' => 'onboarding.started',
            'conditions' => [['type' => 'client.marketing_consent', 'operator' => 'equals', 'value' => true]],
        ]);

        app(StartClientOnboarding::class)->handle($client);
        $event = ScenarioEvent::query()->where('event_name', 'onboarding.started')->sole();
        app(MaterializeScenarioEvent::class)->handle($event->id);

        self::assertSame(0, ScenarioAction::query()->where('scenario_rule_id', $rule->id)->count());
        ClientConsent::factory()->forOrganization($organization)->forClient($client)->create([
            'subject' => ConsentSubject::Marketing->value,
            'granted' => true,
        ]);
        $onboarding = ClientOnboarding::query()->where('organization_id', $organization->id)->sole();
        $onboarding->forceFill(['flow_version' => 'm5b-second-flow'])->save();
        $secondEvent = app(RecordScenarioEvent::class)->onboardingStarted($onboarding, null, CarbonImmutable::now());
        app(MaterializeScenarioEvent::class)->handle($secondEvent->id);

        self::assertSame(1, ScenarioAction::query()->where('scenario_rule_id', $rule->id)->count());
    }

    public function test_internal_members_and_roles_are_same_organization_and_verified_at_delivery(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $first = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $second = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $this->verifiedMember($first);
        $version = $this->template($organization);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'recipient_strategy' => ['type' => 'members', 'user_ids' => [$first->id, $second->id]],
        ]);
        $booking = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'm5b-members', CarbonImmutable::now()->subDay());

        app(MaterializeScenarioEvent::class)->handle($event->id);
        $actions = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->orderBy('recipient_user_id')->get();
        self::assertCount(2, $actions);
        self::assertSame([$first->id, $second->id], $actions->pluck('recipient_user_id')->all());

        foreach ($actions as $action) {
            $this->makeDue($action);
            app(ExecuteScenarioAction::class)->handle($action->id);
        }

        self::assertSame(ScenarioActionStatus::Delivered, $actions[0]->fresh()->status);
        self::assertSame(ScenarioActionStatus::Suppressed, $actions[1]->fresh()->status);

        $roleRule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'rule_key' => 'role-recipient-m5b',
            'recipient_strategy' => ['type' => 'roles', 'roles' => [OrganizationRole::Staff->value]],
        ]);
        $secondEvent = app(RecordScenarioEvent::class)->bookingCompleted(
            $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed),
            'm5b-role',
            CarbonImmutable::now()->subDay(),
        );
        app(MaterializeScenarioEvent::class)->handle($secondEvent->id);
        self::assertSame($first->id, ScenarioAction::query()->where('scenario_rule_id', $roleRule->id)->sole()->recipient_user_id);
    }

    public function test_application_rejects_inactive_or_cross_organization_member_selection(): void
    {
        [$organization, $admin] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $foreignMember = User::factory()->forOrganization($otherOrganization)->create();
        $template = $this->template($organization);
        app(OrganizationContext::class)->set($organization);

        $this->expectException(ValidationException::class);
        app(CreateScenarioRule::class)->handle($admin, [
            'rule_key' => 'foreign-recipient',
            'name' => 'Foreign recipient',
            'trigger_event' => 'booking.completed',
            'is_enabled' => true,
            'delay_value' => 1,
            'delay_unit' => 'days',
            'purpose' => 'service',
            'conditions' => [],
            'recipient_strategy' => ['type' => 'members', 'user_ids' => [$foreignMember->id]],
            'channel_priority' => ['telegram'],
            'template_version_id' => $template->id,
        ]);
    }

    public function test_repeated_seed_is_organization_scoped_and_never_overwrites_rules(): void
    {
        $organization = Organization::factory()->create(['slug' => 'seed-m5b']);

        app(ScenarioNotificationSeeder::class)->run();
        $rule = ScenarioRule::query()->where('organization_id', $organization->id)->where('rule_key', 'post-session-follow-up-24h-en')->sole();
        $rule->forceFill(['name' => 'Owner customized'])->save();
        $template = NotificationTemplate::query()
            ->where('organization_id', $organization->id)
            ->where('template_key', 'post-session-follow-up')
            ->where('locale', 'en')
            ->sole();
        $template->forceFill(['name' => 'Owner customized template'])->save();
        app(ScenarioNotificationSeeder::class)->run();

        self::assertSame('Owner customized', $rule->fresh()->name);
        self::assertSame('Owner customized template', $template->fresh()->name);
        $expectedRuleKeys = [
            'b2b-sales-call-ready-client-en',
            'b2b-sales-call-ready-client-ru',
            'b2b-sales-call-ready-specialist',
            'booking-cancelled-client-en',
            'booking-cancelled-client-ru',
            'booking-cancelled-specialist',
            'booking-completed-feedback-en',
            'booking-completed-feedback-ru',
            'booking-confirmed-client-en',
            'booking-confirmed-client-ru',
            'booking-confirmed-specialist',
            'booking-created-client-en',
            'booking-created-client-ru',
            'booking-created-specialist',
            'booking-rescheduled-client-en',
            'booking-rescheduled-client-ru',
            'booking-rescheduled-specialist',
            'post-session-follow-up-24h-en',
            'post-session-follow-up-24h-ru',
            'post-session-follow-up-48h-en',
            'post-session-follow-up-48h-ru',
            'post-session-follow-up-72h-en',
            'post-session-follow-up-72h-ru',
        ];
        $ruleKeys = ScenarioRule::query()
            ->where('organization_id', $organization->id)
            ->orderBy('rule_key')
            ->pluck('rule_key')
            ->all();
        self::assertSame($expectedRuleKeys, $ruleKeys);
        self::assertSame(count($expectedRuleKeys), count(array_unique($ruleKeys)));
        self::assertSame(count($expectedRuleKeys), ScenarioRule::query()->where('organization_id', $organization->id)->count());
        self::assertSame([
            'b2b-sales-call-ready:en',
            'b2b-sales-call-ready:ru',
            'b2b-sales-call-ready-specialist:ru',
            'booking-cancelled:en',
            'booking-cancelled:ru',
            'booking-cancelled-specialist:ru',
            'booking-completed-feedback:en',
            'booking-completed-feedback:ru',
            'booking-confirmed:en',
            'booking-confirmed:ru',
            'booking-confirmed-specialist:ru',
            'booking-created:en',
            'booking-created:ru',
            'booking-created-specialist:ru',
            'booking-rescheduled:en',
            'booking-rescheduled:ru',
            'booking-rescheduled-specialist:ru',
            'post-session-follow-up:en',
            'post-session-follow-up:ru',
        ], NotificationTemplate::query()
            ->where('organization_id', $organization->id)
            ->orderBy('template_key')
            ->orderBy('locale')
            ->get(['template_key', 'locale'])
            ->map(static fn (NotificationTemplate $template): string => $template->template_key.':'.$template->locale)
            ->all());
        self::assertSame(
            [24, 48, 72],
            ScenarioRule::query()
                ->where('organization_id', $organization->id)
                ->where('rule_key', 'like', 'post-session-follow-up-%-en')
                ->orderBy('delay_value')
                ->pluck('delay_value')
                ->all(),
        );
    }

    public function test_crm_can_configure_onboarding_trigger_repeat_and_safe_condition(): void
    {
        [$organization, $admin] = $this->fixture();
        $template = $this->template($organization);
        $this->setFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(CreateScenarioRulePage::class)
            ->fillForm([
                'name' => 'Onboarding reminder',
                'trigger_event' => 'onboarding.started',
                'is_enabled' => false,
                'delay_value' => 1,
                'delay_unit' => 'days',
                'max_occurrences' => 3,
                'repeat_interval_value' => 2,
                'repeat_interval_unit' => 'days',
                'purpose' => 'service',
                'conditions' => [['type' => 'onboarding.completed', 'operator' => 'equals', 'value' => 'false']],
                'recipient_strategy' => ['type' => 'client'],
                'channel_priority' => ['telegram'],
                'template_version_id' => $template->id,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $rule = ScenarioRule::query()->sole();
        self::assertSame('onboarding.started', $rule->trigger_event->value);
        self::assertSame(3, $rule->max_occurrences);
        self::assertSame(2, $rule->repeat_interval_value);
        self::assertSame('onboarding.completed', $rule->conditions[0]['type']);
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
            'template_key' => 'm5b-'.fake()->unique()->slug(),
        ]);

        return NotificationTemplateVersion::factory()->forTemplate($template)->create();
    }

    private function verifiedClient(Client $client): void
    {
        ClientChannelIdentity::factory()->forClient($client)->create([
            'channel' => 'telegram',
            'external_id' => 'client-'.$client->id.'-m5b',
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
    }

    private function verifiedMember(User $user): void
    {
        $membership = $user->memberships()->firstOrFail();
        $identity = new OrganizationChannelIdentity;
        $identity->forceFill([
            'organization_id' => $membership->organization_id,
            'user_id' => $user->id,
            'channel' => 'telegram',
            'external_id' => 'member-'.$user->id.'-m5b',
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'test',
            'verified_at' => now(),
        ])->save();
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

    private function futureBooking(
        Organization $organization,
        Client $client,
        Specialist $specialist,
        Service $service,
        BookingStatus $status,
        CarbonImmutable $start,
    ): Booking {
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
