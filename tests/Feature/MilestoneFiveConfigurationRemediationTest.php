<?php

namespace Tests\Feature;

use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Application\CreateScenarioRule;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Application\ScheduleScenarioWork;
use App\Modules\Scenarios\Application\UpdateScenarioRule;
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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class MilestoneFiveConfigurationRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_membership_deactivated_after_materialization_is_suppressed_before_send(): void
    {
        [$organization, $booking, $version] = $this->scenarioFixture();
        $recipient = User::factory()->forOrganization($organization)->create();
        OrganizationChannelIdentity::factory()->forUser($recipient)->verified()->create();
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 0,
            'recipient_strategy' => ['type' => 'members', 'user_ids' => [$recipient->id]],
        ]);
        $action = $this->materialize($booking, $rule);
        OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $recipient->id)
            ->update(['is_active' => false]);
        $channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertSame('no_available_channel', $action->fresh()->terminal_reason);
        self::assertCount(0, $channel->messages);
    }

    #[DataProvider('unusableIdentityStatuses')]
    public function test_unverified_or_revoked_internal_identity_is_not_used(string $status): void
    {
        [$organization, $booking, $version] = $this->scenarioFixture();
        $recipient = User::factory()->forOrganization($organization)->create();
        OrganizationChannelIdentity::factory()->forUser($recipient)->create([
            'verification_status' => $status,
            'verification_method' => $status === ChannelIdentityStatus::Revoked->value ? 'revoked' : null,
            'verified_at' => null,
        ]);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 0,
            'recipient_strategy' => ['type' => 'members', 'user_ids' => [$recipient->id]],
        ]);
        $action = $this->materialize($booking, $rule);
        $channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertCount(0, $channel->messages);
    }

    public function test_internal_identity_from_another_organization_is_not_used(): void
    {
        [$organization, $booking, $version] = $this->scenarioFixture();
        $recipient = User::factory()->forOrganization($organization)->create();
        $otherOrganization = Organization::factory()->create();
        OrganizationMembership::factory()
            ->forOrganization($otherOrganization)
            ->forUser($recipient)
            ->create();
        $identity = new OrganizationChannelIdentity;
        $identity->forceFill([
            'organization_id' => $otherOrganization->id,
            'user_id' => $recipient->id,
            'channel' => 'telegram',
            'external_id' => 'other-organization-chat',
            'verification_status' => ChannelIdentityStatus::Verified,
            'verification_method' => 'test',
            'verified_at' => now(),
        ])->save();
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 0,
            'recipient_strategy' => ['type' => 'members', 'user_ids' => [$recipient->id]],
        ]);
        $action = $this->materialize($booking, $rule);
        $channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertCount(0, $channel->messages);
    }

    #[DataProvider('invalidConditionConfigurations')]
    public function test_application_rejects_malformed_or_semantically_invalid_conditions(mixed $conditions): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        $template = NotificationTemplate::factory()->forOrganization($organization)->create();
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create();

        try {
            app(CreateScenarioRule::class)->handle($admin, [
                'rule_key' => 'invalid-condition-rule',
                'name' => 'Invalid condition rule',
                'trigger_event' => 'booking.completed',
                'is_enabled' => true,
                'delay_value' => 0,
                'delay_unit' => 'hours',
                'purpose' => 'service',
                'conditions' => $conditions,
                'recipient_strategy' => ['type' => 'client'],
                'channel_priority' => ['telegram'],
                'template_version_id' => $version->id,
            ]);
            self::fail('Invalid scenario conditions were accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, ScenarioRule::query()->count());
        }
    }

    public function test_materialized_action_keeps_versioned_condition_semantics_after_rule_update(): void
    {
        [$organization, $booking, $version] = $this->scenarioFixture();
        $admin = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        ClientChannelIdentity::factory()->forClient($booking->client)->create([
            'channel' => 'telegram',
            'external_id' => 'snapshot-client-chat',
            'verification_status' => ChannelIdentityStatus::Verified,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->createdBy($admin)->create([
            'rule_key' => 'condition-snapshot-rule',
            'delay_value' => 0,
            'conditions' => [['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed']],
        ]);
        $action = $this->materialize($booking, $rule);

        app(UpdateScenarioRule::class)->handle($admin, $rule, [
            'rule_key' => $rule->rule_key,
            'name' => $rule->name,
            'trigger_event' => 'booking.completed',
            'is_enabled' => true,
            'delay_value' => 0,
            'delay_unit' => 'hours',
            'purpose' => 'service',
            'conditions' => [['type' => 'booking.status', 'operator' => 'not_equals', 'value' => 'completed']],
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
            'template_version_id' => $version->id,
        ]);
        $channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(1, $action->rule_version);
        self::assertSame(2, $rule->fresh()->version);
        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertCount(1, $channel->messages);
    }

    public function test_malformed_materialized_condition_snapshot_fails_closed(): void
    {
        [$organization, $booking, $version] = $this->scenarioFixture();
        ClientChannelIdentity::factory()->forClient($booking->client)->create([
            'channel' => 'telegram',
            'external_id' => 'malformed-snapshot-chat',
            'verification_status' => ChannelIdentityStatus::Verified,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 0,
        ]);
        $action = $this->materialize($booking, $rule);
        $action->forceFill(['condition_snapshot' => ['malformed']])->save();
        $channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertSame('current_conditions_not_met', $action->fresh()->terminal_reason);
        self::assertCount(0, $channel->messages);
    }

    #[DataProvider('persistedNonArrayConditionSnapshots')]
    public function test_persisted_non_array_condition_snapshot_is_terminally_suppressed(mixed $snapshot): void
    {
        [$organization, $booking, $version] = $this->scenarioFixture();
        ClientChannelIdentity::factory()->forClient($booking->client)->create([
            'channel' => 'telegram',
            'external_id' => 'non-array-snapshot-chat',
            'verification_status' => ChannelIdentityStatus::Verified,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 0,
        ]);
        $action = $this->materialize($booking, $rule);
        DB::table('scenario_actions')
            ->where('id', $action->id)
            ->update(['condition_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $action->refresh();
        self::assertFalse(is_array($action->condition_snapshot));
        $channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));
        Queue::fake();

        app(ExecuteScenarioAction::class)->handle($action->id);

        $processedAction = $action->fresh();
        self::assertSame(ScenarioActionStatus::Suppressed, $processedAction->status);
        self::assertSame('current_conditions_not_met', $processedAction->terminal_reason);
        self::assertSame(0, $processedAction->deliveries()->sum('attempt_count'));
        self::assertCount(0, $channel->messages);
        self::assertSame(0, app(ScheduleScenarioWork::class)->handle()['actions']);
        Queue::assertNothingPushed();
    }

    public function test_valid_array_condition_snapshot_remains_eligible(): void
    {
        [$organization, $booking, $version] = $this->scenarioFixture();
        ClientChannelIdentity::factory()->forClient($booking->client)->create([
            'channel' => 'telegram',
            'external_id' => 'valid-array-snapshot-chat',
            'verification_status' => ChannelIdentityStatus::Verified,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 0,
        ]);
        $action = $this->materialize($booking, $rule);
        self::assertIsArray($action->condition_snapshot);
        $channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertCount(1, $channel->messages);
    }

    public function test_filament_template_edit_creates_new_version_and_keeps_existing_action_pinned(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $template = NotificationTemplate::factory()->forOrganization($organization)->create([
            'template_key' => 'filament-versioned-template',
            'locale' => 'en',
            'name' => 'Before',
        ]);
        $versionOne = NotificationTemplateVersion::factory()->forTemplate($template)->createdBy($admin)->create([
            'body' => 'Version one {{ client.full_name }}.',
        ]);
        $event = ScenarioEvent::factory()->forOrganization($organization)->create();
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($versionOne)->createdBy($admin)->create();
        $action = ScenarioAction::factory()
            ->forOrganization($organization)
            ->forEvent($event)
            ->forRule($rule)
            ->forTemplate($versionOne)
            ->forClient($client)
            ->create();
        $this->setFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(EditNotificationTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm([
                'name' => 'After',
                'purpose' => 'service',
                'is_active' => true,
                'subject' => null,
                'body' => '<p>Version two {{ client.full_name }}.</p>',
                'variables' => ['client.full_name'],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $versions = $template->versions()->orderBy('version')->get();
        self::assertCount(2, $versions);
        self::assertSame('Version one {{ client.full_name }}.', $versions[0]->body);
        self::assertSame('<p>Version two {{ client.full_name }}.</p>', $versions[1]->body);
        self::assertSame(1, $versions[0]->version);
        self::assertSame(2, $versions[1]->version);
        self::assertSame($versionOne->id, $action->fresh()->template_version_id);
    }

    public static function unusableIdentityStatuses(): array
    {
        return [
            'unverified' => [ChannelIdentityStatus::Unverified->value],
            'revoked' => [ChannelIdentityStatus::Revoked->value],
        ];
    }

    public static function invalidConditionConfigurations(): array
    {
        return [
            'non-array condition set' => ['malformed'],
            'unsupported operator' => [[['type' => 'booking.status', 'operator' => 'contains', 'value' => 'completed']]],
            'invalid booking status' => [[['type' => 'booking.status', 'operator' => 'not_equals', 'value' => 'invalid']]],
            'invalid client language' => [[['type' => 'client.language', 'operator' => 'equals', 'value' => 'de']]],
        ];
    }

    public static function persistedNonArrayConditionSnapshots(): array
    {
        return [
            'json scalar' => ['scalar'],
            'json null' => [null],
        ];
    }

    /** @return array{Organization, Booking, NotificationTemplateVersion} */
    private function scenarioFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $client = Client::factory()->forOrganization($organization)->create([
            'language' => 'en',
            'timezone' => 'UTC',
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create();
        $start = CarbonImmutable::now()->subHours(3);
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Completed->value,
                'starts_at' => $start,
                'ends_at' => $start->addHour(),
                'blocking_ends_at' => $start->addHour(),
                'schedule_timezone' => 'UTC',
            ]);
        $template = NotificationTemplate::factory()->forOrganization($organization)->create();
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create();

        return [$organization, $booking, $version];
    }

    private function materialize(Booking $booking, ScenarioRule $rule): ScenarioAction
    {
        $event = app(RecordScenarioEvent::class)->bookingCompleted(
            $booking,
            'configuration-remediation-'.fake()->uuid(),
            CarbonImmutable::now(),
        );
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->id)->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        return $action->refresh();
    }

    private function setFilamentContext(User $admin, Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->id);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(OrganizationContext::class)->set($organization);
    }
}
