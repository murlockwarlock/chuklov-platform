<?php

namespace Tests\Feature;

use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Application\ScheduleScenarioWork;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDeliveryAttempt;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Jobs\ExecuteScenarioAction as ExecuteScenarioActionJob;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class MilestoneFiveDeliveryRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retryable_delivery_remains_durable_while_attempts_remain(): void
    {
        config()->set('scenarios.deliveries.max_attempts', 3);
        config()->set('scenarios.deliveries.retry_after_seconds', 60);
        $primary = new RecordingNotificationChannel(
            'primary',
            NotificationDeliveryResult::retryable('temporary_failure'),
        );
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$primary]));
        $action = $this->materializedAction(['primary']);

        app(ExecuteScenarioAction::class)->handle($action->id);

        $delivery = $action->deliveries()->sole();
        self::assertSame(ScenarioActionStatus::Retryable, $action->fresh()->status);
        self::assertSame(ScenarioDeliveryStatus::Retryable, $delivery->fresh()->status);
        self::assertSame(1, $delivery->attempts()->count());
        self::assertSame(NotificationDeliveryOutcome::Retryable, $delivery->attempts()->sole()->outcome);
        self::assertNotNull($delivery->fresh()->next_attempt_at);
        self::assertCount(1, $primary->messages);
    }

    public function test_exhausted_primary_falls_back_and_scheduler_replay_does_not_duplicate_attempts(): void
    {
        config()->set('scenarios.deliveries.max_attempts', 2);
        config()->set('scenarios.deliveries.retry_after_seconds', 0);
        $primary = new RecordingNotificationChannel(
            'primary',
            NotificationDeliveryResult::retryable('temporary_failure'),
        );
        $fallback = new RecordingNotificationChannel('fallback');
        $this->app->instance(
            NotificationChannelRegistry::class,
            new NotificationChannelRegistry([$primary, $fallback]),
        );
        $action = $this->materializedAction(['primary', 'fallback']);

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Retryable, $action->fresh()->status);
        Queue::fake();
        $scheduled = app(ScheduleScenarioWork::class)->handle();
        self::assertSame(1, $scheduled['actions']);
        Queue::assertPushed(
            ExecuteScenarioActionJob::class,
            fn (ExecuteScenarioActionJob $job): bool => $job->scenarioActionId === $action->id,
        );

        app(ExecuteScenarioAction::class)->handle($action->id);
        app(ExecuteScenarioAction::class)->handle($action->id);

        $deliveries = $action->deliveries()->orderBy('priority')->get();
        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame(ScenarioDeliveryStatus::PermanentFailure, $deliveries[0]->status);
        self::assertSame('retryable_attempts_exhausted', $deliveries[0]->terminal_reason);
        self::assertSame(2, $deliveries[0]->attempts()->count());
        self::assertSame(ScenarioDeliveryStatus::Delivered, $deliveries[1]->status);
        self::assertSame(1, $deliveries[1]->attempts()->count());
        self::assertCount(2, $primary->messages);
        self::assertCount(1, $fallback->messages);
        self::assertSame(0, app(ScheduleScenarioWork::class)->handle()['actions']);
    }

    public function test_stale_action_before_any_delivery_attempt_is_recovered(): void
    {
        $channel = new RecordingNotificationChannel('primary');
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));
        $action = $this->materializedAction(['primary']);
        $action->forceFill([
            'status' => ScenarioActionStatus::Processing,
            'processing_started_at' => now()->subMinutes(10),
        ])->save();

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Retryable, $action->fresh()->status);
        self::assertSame(0, ScenarioDeliveryAttempt::query()->count());

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame(1, ScenarioDeliveryAttempt::query()->count());
        self::assertCount(1, $channel->messages);
    }

    public function test_stale_state_between_terminal_primary_and_pending_fallback_is_recovered(): void
    {
        $fallback = new RecordingNotificationChannel('fallback');
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$fallback]));
        $action = $this->materializedAction(['primary', 'fallback']);
        $primary = $action->deliveries()->where('channel', 'primary')->sole();
        $primary->forceFill([
            'status' => ScenarioDeliveryStatus::PermanentFailure,
            'attempt_count' => 1,
            'next_attempt_at' => null,
            'terminal_reason' => 'provider_permanent_failure',
        ])->save();
        ScenarioDeliveryAttempt::factory()->forDelivery($primary)->create([
            'attempt_number' => 1,
            'outcome' => NotificationDeliveryOutcome::PermanentFailure,
        ]);
        $action->forceFill([
            'status' => ScenarioActionStatus::Processing,
            'processing_started_at' => now()->subMinutes(10),
        ])->save();

        app(ExecuteScenarioAction::class)->handle($action->id);
        self::assertSame(ScenarioActionStatus::Retryable, $action->fresh()->status);

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame(
            ScenarioDeliveryStatus::Delivered,
            $action->deliveries()->where('channel', 'fallback')->sole()->status,
        );
        self::assertCount(1, $fallback->messages);
    }

    public function test_stale_in_flight_attempt_is_recorded_as_unknown_without_another_send(): void
    {
        $channel = new RecordingNotificationChannel('primary');
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));
        $action = $this->materializedAction(['primary']);
        $delivery = $action->deliveries()->sole();
        $action->forceFill([
            'status' => ScenarioActionStatus::Processing,
            'processing_started_at' => now()->subMinutes(10),
        ])->save();
        $delivery->forceFill([
            'status' => ScenarioDeliveryStatus::Processing,
            'attempt_count' => 1,
            'processing_started_at' => now()->subMinutes(10),
        ])->save();
        ScenarioDeliveryAttempt::factory()->forDelivery($delivery)->create([
            'attempt_number' => 1,
            'outcome' => NotificationDeliveryOutcome::Unknown,
        ]);

        app(ExecuteScenarioAction::class)->handle($action->id);

        self::assertSame(ScenarioActionStatus::Failed, $action->fresh()->status);
        self::assertSame('delivery_outcome_unknown', $action->fresh()->terminal_reason);
        self::assertSame(ScenarioDeliveryStatus::PermanentFailure, $delivery->fresh()->status);
        self::assertSame('worker_lost_before_outcome', $delivery->attempts()->sole()->error_code);
        self::assertCount(0, $channel->messages);
        self::assertSame(0, app(ScheduleScenarioWork::class)->handle()['actions']);
    }

    /** @param list<string> $channels */
    private function materializedAction(array $channels): ScenarioAction
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

        foreach ($channels as $channel) {
            ClientChannelIdentity::factory()->forClient($client)->create([
                'channel' => $channel,
                'external_id' => $channel.'-'.$client->id,
                'verification_status' => ChannelIdentityStatus::Verified,
                'verification_method' => 'test',
                'verified_at' => now(),
            ]);
        }

        $template = NotificationTemplate::factory()->forOrganization($organization)->create();
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create();
        ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'delay_value' => 0,
            'conditions' => [['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed']],
            'channel_priority' => $channels,
        ]);
        $event = app(RecordScenarioEvent::class)->bookingCompleted(
            $booking,
            'delivery-remediation-'.fake()->uuid(),
            CarbonImmutable::now(),
        );
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        return $action->refresh();
    }
}
