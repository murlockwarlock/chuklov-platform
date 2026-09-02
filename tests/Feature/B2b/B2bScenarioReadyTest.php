<?php

namespace Tests\Feature\B2b;

use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\ValueObjects\NotificationActionButton;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class B2bScenarioReadyTest extends TestCase
{
    use RefreshDatabase;

    private RecordingNotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$this->channel]));
    }

    public function test_ready_action_is_suppressed_when_the_call_is_cancelled_after_materialization(): void
    {
        $scenario = $this->readyScenario();
        $scenario['call']->forceFill([
            'status' => 'cancelled',
            'provider_join_url' => null,
            'event_version' => 2,
        ])->save();

        $this->execute($scenario['action']);

        self::assertSame(ScenarioActionStatus::Suppressed, $scenario['action']->fresh()->status);
        self::assertSame(ScenarioDeliveryStatus::Suppressed, $scenario['action']->deliveries()->sole()->status);
        self::assertSame('b2b_sales_call_changed', $scenario['action']->fresh()->terminal_reason);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_ready_client_notification_contains_schedule_and_an_inline_meeting_button(): void
    {
        $scenario = $this->readyScenario();
        $this->execute($scenario['action']);

        $message = $this->channel->messages[0] ?? null;

        self::assertNotNull($message);
        $start = $scenario['call']->startsAtUtc();
        self::assertSame('Встреча: '.$start->toDateString().' '.$start->format('H:i').'.', $message->body);
        self::assertStringNotContainsString('https://zoom.us/j/ready', $message->body);
        self::assertInstanceOf(NotificationActionButton::class, $message->actionButton);
        self::assertSame('https://zoom.us/j/ready', $message->actionButton->url);
        self::assertSame('Join meeting', $message->actionButton->text);
    }

    public function test_ready_action_is_suppressed_when_the_call_is_rescheduled_to_a_new_generation(): void
    {
        $scenario = $this->readyScenario();
        $scenario['call']->forceFill([
            'starts_at' => CarbonImmutable::parse('2026-09-01 15:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 16:00:00 UTC'),
            'provider_correlation_key' => 'generation-two',
            'provider_join_url' => 'https://zoom.us/j/new-generation',
            'provider_sync_version' => 2,
            'event_version' => 2,
        ])->save();

        $this->execute($scenario['action']);

        self::assertSame(ScenarioActionStatus::Suppressed, $scenario['action']->fresh()->status);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_ready_action_is_suppressed_when_a_manual_link_is_removed_before_delivery(): void
    {
        $scenario = $this->readyScenario(
            mode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/b2b',
        );
        $scenario['call']->forceFill(['manual_meeting_url' => null])->save();

        $this->execute($scenario['action']);

        self::assertSame(ScenarioActionStatus::Suppressed, $scenario['action']->fresh()->status);
        self::assertSame(ScenarioDeliveryStatus::Suppressed, $scenario['action']->deliveries()->sole()->status);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_ready_action_is_suppressed_when_meeting_mode_changes_before_delivery(): void
    {
        $automatic = $this->readyScenario();
        $automatic['call']->forceFill([
            'meeting_mode' => VideoMeetingMode::Manual,
            'provider_name' => null,
            'provider_meeting_id' => null,
            'provider_meeting_uuid' => null,
            'provider_join_url' => null,
            'manual_meeting_url' => 'https://meet.example.test/new',
            'provider_sync_status' => VideoMeetingSyncStatus::NotRequired,
            'provider_correlation_key' => null,
            'provider_sync_version' => 2,
            'event_version' => 2,
        ])->save();
        $this->execute($automatic['action']);

        $manual = $this->readyScenario(
            mode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/old',
        );
        $manual['call']->forceFill([
            'meeting_mode' => VideoMeetingMode::Automatic,
            'manual_meeting_url' => null,
            'provider_name' => 'zoom',
            'provider_sync_status' => VideoMeetingSyncStatus::Pending,
            'provider_operation' => 'create',
            'provider_correlation_key' => 'generation-two',
            'provider_sync_version' => 2,
            'event_version' => 2,
        ])->save();
        $this->execute($manual['action']);

        self::assertSame(ScenarioActionStatus::Suppressed, $automatic['action']->fresh()->status);
        self::assertSame(ScenarioActionStatus::Suppressed, $manual['action']->fresh()->status);
        self::assertCount(0, $this->channel->messages);
    }

    public function test_ready_event_is_suppressed_before_materialization_when_the_call_changed(): void
    {
        $scenario = $this->scenario();
        $scenario['call']->forceFill([
            'status' => 'cancelled',
            'event_version' => 2,
            'provider_join_url' => null,
        ])->save();

        app(MaterializeScenarioEvent::class)->handle($scenario['event']->getKey());

        self::assertSame(ScenarioEventStatus::Processed, $scenario['event']->fresh()->status);
        self::assertSame('b2b_sales_call_changed', $scenario['event']->fresh()->last_error_code);
        self::assertSame(0, ScenarioAction::query()->where('scenario_event_id', $scenario['event']->getKey())->count());
    }

    private function execute(ScenarioAction $action): void
    {
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($action->getKey());
    }

    private function readyScenario(
        VideoMeetingMode $mode = VideoMeetingMode::Automatic,
        ?string $manualMeetingUrl = null,
    ): array {
        $scenario = $this->scenario($mode, $manualMeetingUrl);
        app(MaterializeScenarioEvent::class)->handle($scenario['event']->getKey());

        return [
            ...$scenario,
            'action' => ScenarioAction::query()->where('scenario_event_id', $scenario['event']->getKey())->sole(),
        ];
    }

    private function scenario(
        VideoMeetingMode $mode = VideoMeetingMode::Automatic,
        ?string $manualMeetingUrl = null,
    ): array {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'en', 'timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $lead = B2bLead::factory()->forClient($client)->create(['status' => B2bLeadStatus::ZoomScheduled]);
        $call = B2bSalesCall::factory()->forLead($lead)->forSpecialist($specialist)->create([
            'meeting_mode' => $mode,
            'provider_name' => $mode === VideoMeetingMode::Automatic ? 'zoom' : null,
            'provider_meeting_id' => $mode === VideoMeetingMode::Automatic ? 'zoom-1' : null,
            'provider_meeting_uuid' => $mode === VideoMeetingMode::Automatic ? 'uuid-1' : null,
            'provider_join_url' => $mode === VideoMeetingMode::Automatic ? 'https://zoom.us/j/ready' : null,
            'manual_meeting_url' => $manualMeetingUrl,
            'provider_sync_status' => $mode === VideoMeetingMode::Automatic
                ? VideoMeetingSyncStatus::Ready
                : VideoMeetingSyncStatus::NotRequired,
            'provider_operation' => null,
            'provider_correlation_key' => $mode === VideoMeetingMode::Automatic ? 'generation-one' : null,
        ]);
        ClientChannelIdentity::factory()->forClient($client)->create([
            'channel' => 'telegram',
            'external_id' => 'client-chat-'.$client->getKey(),
            'verification_status' => ChannelIdentityStatus::Verified,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
        $template = NotificationTemplate::factory()->forOrganization($organization)->create([
            'purpose' => 'service',
            'locale' => 'en',
        ]);
        $templateVersion = NotificationTemplateVersion::factory()->forTemplate($template)->create([
            'body' => 'Встреча: {{ sales_call.local_date }} {{ sales_call.local_time }}.',
            'variables' => ['sales_call.local_date', 'sales_call.local_time'],
        ]);
        ScenarioRule::factory()->forOrganization($organization)->usingTemplate($templateVersion)->create([
            'trigger_event' => 'b2b.sales_call.ready',
            'delay_value' => 0,
            'delay_unit' => 'minutes',
            'purpose' => 'service',
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
        ]);
        $event = app(RecordScenarioEvent::class)->b2bSalesCallReady($call, CarbonImmutable::now('UTC'));

        return compact('call', 'client', 'event', 'lead', 'organization', 'specialist');
    }
}
