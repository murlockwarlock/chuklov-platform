<?php

namespace Tests\Feature;

use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\ConsumeFinanceSettlementEvent;
use App\Modules\Referrals\Jobs\ProcessReferralIntegrationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class M11AReferralIntegrationEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_and_retryable_events_are_not_claimed_before_available_at(): void
    {
        $organization = Organization::factory()->create();
        $availableAt = now()->addMinute();

        foreach ([IntegrationEventStatus::Pending, IntegrationEventStatus::Retryable] as $index => $status) {
            $event = $this->event($organization, $status, $availableAt, 'future-'.$index);

            self::assertNull(app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey()));
            $event->refresh();
            self::assertSame($status, $event->status);
            self::assertSame(0, (int) $event->attempt_count);
            self::assertNull($event->processing_token);
        }
    }

    public function test_processing_event_without_a_started_lease_is_not_claimed(): void
    {
        $organization = Organization::factory()->create();
        $event = $this->event(
            organization: $organization,
            status: IntegrationEventStatus::Processing,
            availableAt: now()->subMinute(),
            key: 'processing-without-lease',
            processingToken: str_repeat('a', 64),
        );

        self::assertNull(app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey()));
        $event->refresh();
        self::assertSame(IntegrationEventStatus::Processing, $event->status);
        self::assertSame(0, (int) $event->attempt_count);
        self::assertSame(str_repeat('a', 64), $event->processing_token);
    }

    public function test_due_event_is_claimed_and_permanent_validation_failure_is_terminal(): void
    {
        $organization = Organization::factory()->create();
        $event = $this->event(
            organization: $organization,
            status: IntegrationEventStatus::Pending,
            availableAt: now()->subSecond(),
            key: 'due-event',
        );

        self::assertNull(app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey()));
        $event->refresh();

        self::assertSame(IntegrationEventStatus::Failed, $event->status);
        self::assertSame(1, (int) $event->attempt_count);
        self::assertNull($event->processing_token);
    }

    public function test_duplicate_queue_dispatches_do_not_bypass_durable_backoff(): void
    {
        $organization = Organization::factory()->create();
        $event = $this->event(
            organization: $organization,
            status: IntegrationEventStatus::Retryable,
            availableAt: now()->addMinute(),
            key: 'duplicate-before-due',
            attemptCount: 1,
        );

        Queue::fake();
        ProcessReferralIntegrationEvent::dispatch($event->getKey())->onQueue('referrals');
        ProcessReferralIntegrationEvent::dispatch($event->getKey())->onQueue('referrals');
        Queue::assertPushed(ProcessReferralIntegrationEvent::class, 2);

        $consumer = app(ConsumeFinanceSettlementEvent::class);
        (new ProcessReferralIntegrationEvent($event->getKey()))->handle($consumer);
        (new ProcessReferralIntegrationEvent($event->getKey()))->handle($consumer);

        $event->refresh();
        self::assertSame(IntegrationEventStatus::Retryable, $event->status);
        self::assertSame(1, (int) $event->attempt_count);
        self::assertNull($event->processing_token);
    }

    public function test_max_attempts_remains_authoritative_for_due_retryable_events(): void
    {
        $organization = Organization::factory()->create();
        $maxAttempts = (int) config('referrals.events.max_attempts', 5);
        $event = $this->event(
            organization: $organization,
            status: IntegrationEventStatus::Retryable,
            availableAt: now()->subSecond(),
            key: 'max-attempts',
            attemptCount: $maxAttempts,
        );

        self::assertNull(app(ConsumeFinanceSettlementEvent::class)->handle($event->getKey()));
        $event->refresh();

        self::assertSame(IntegrationEventStatus::Failed, $event->status);
        self::assertSame($maxAttempts, (int) $event->attempt_count);
        self::assertNull($event->processing_token);
    }

    public function test_referral_job_uses_one_queue_attempt(): void
    {
        self::assertSame(1, (new ProcessReferralIntegrationEvent(1))->tries);
    }

    private function event(
        Organization $organization,
        IntegrationEventStatus $status,
        Carbon $availableAt,
        string $key,
        int $attemptCount = 0,
        ?string $processingToken = null,
    ): IntegrationEvent {
        $event = new IntegrationEvent;
        $event->forceFill([
            'organization_id' => $organization->getKey(),
            'event_type' => IntegrationEventType::FinanceObligationSettled,
            'aggregate_type' => 'financial_obligation',
            'aggregate_id' => 1,
            'idempotency_key' => 'm11a-'.$key,
            'payload' => [],
            'status' => $status,
            'attempt_count' => $attemptCount,
            'occurred_at' => now(),
            'available_at' => $availableAt,
            'processing_started_at' => null,
            'processing_token' => $processingToken,
        ]);
        $event->save();

        return $event->refresh();
    }
}
