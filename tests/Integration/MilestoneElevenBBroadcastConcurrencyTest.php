<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Broadcasts\Application\CancelBroadcastCampaign;
use App\Modules\Broadcasts\Application\MaterializeBroadcastAudience;
use App\Modules\Broadcasts\Application\ProcessBroadcastBatch;
use App\Modules\Broadcasts\Application\ScheduleBroadcastWork;
use App\Modules\Broadcasts\Application\SetBroadcastClientClassification;
use App\Modules\Broadcasts\Application\StartBroadcastCampaign;
use App\Modules\Broadcasts\Application\TestBroadcastCampaign;
use App\Modules\Broadcasts\Application\UpdateBroadcastCampaign;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastBatch;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientTag;
use App\Modules\Broadcasts\Domain\Models\BroadcastDeliveryAttempt;
use App\Modules\Broadcasts\Domain\Models\BroadcastRecipient;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class MilestoneElevenBBroadcastConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    public function test_concurrent_due_claims_dispatch_one_campaign_once(): void
    {
        $this->requirePostgres();
        [$organization, $actor, $campaign] = $this->fixture();
        app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['state' => BroadcastCampaignState::Scheduled, 'scheduled_at' => now()->subMinute()])->save();

        $results = Concurrency::driver('process')->run([
            static fn (): int => self::scheduleInProcess(),
            static fn (): int => self::scheduleInProcess(),
        ]);

        self::assertSame(1, array_sum($results));
        self::assertSame(BroadcastCampaignState::Dispatching, $campaign->refresh()->state);
        self::assertSame(1, BroadcastBatch::query()->where('campaign_id', $campaign->getKey())->value('dispatch_attempt_count'));
    }

    public function test_duplicate_materializers_create_one_snapshot_and_one_logical_recipient_for_several_predicates(): void
    {
        $this->requirePostgres();
        [$organization, $actor, $campaign] = $this->fixture();
        $client = $this->eligibleClient($organization);
        $campaign->forceFill(['segment_definition' => [['key' => 'language', 'operator' => 'equals', 'value' => 'ru'], ['key' => 'verified_channel', 'operator' => 'equals', 'value' => 'telegram']]])->save();

        Concurrency::driver('process')->run([
            static fn (): int => self::materializeInProcess($campaign->id),
            static fn (): int => self::materializeInProcess($campaign->id),
        ]);

        self::assertSame(1, DB::table('broadcast_audience_snapshots')->where('campaign_id', $campaign->id)->count());
        self::assertSame(1, BroadcastRecipient::query()->where('campaign_id', $campaign->id)->where('client_id', $client->id)->count());
    }

    public function test_concurrent_batch_workers_send_and_record_success_once_and_replay_is_a_noop(): void
    {
        $this->requirePostgres();
        [$organization, $actor, $campaign] = $this->fixture();
        $this->eligibleClient($organization);
        app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'dispatch_started_at' => now()])->save();
        $batchId = (int) DB::table('broadcast_batches')->where('campaign_id', $campaign->id)->value('id');

        $results = Concurrency::driver('process')->run([
            static fn (): int => self::processBatchInProcess($organization->id, $batchId),
            static fn (): int => self::processBatchInProcess($organization->id, $batchId),
        ]);
        $replay = self::processBatchInProcess($organization->id, $batchId);

        self::assertSame(1, array_sum($results));
        self::assertSame(0, $replay);
        self::assertSame(1, DB::table('broadcast_delivery_attempts')->count());
        self::assertSame(BroadcastRecipientState::Delivered, BroadcastRecipient::query()->sole()->state);
    }

    public function test_tenant_snapshot_is_deterministic_and_suppression_or_unverified_channel_fails_closed(): void
    {
        $this->requirePostgres();
        [$organization, $actor, $campaign] = $this->fixture();
        $eligible = $this->eligibleClient($organization);
        $withdrawn = $this->eligibleClient($organization);
        ClientConsent::factory()->forClient($withdrawn)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => false, 'recorded_at' => now()->addSecond()]);
        $unverified = Client::factory()->forOrganization($organization)->create(['language' => 'ru']);
        ClientConsent::factory()->forClient($unverified)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => true]);
        ClientChannelIdentity::factory()->forClient($unverified)->create(['channel' => 'telegram', 'verification_status' => ChannelIdentityStatus::Unverified->value]);
        $foreignOrganization = Organization::factory()->create();
        $this->eligibleClient($foreignOrganization);

        $snapshot = app(MaterializeBroadcastAudience::class)->handle($campaign);
        $eligible->forceFill(['language' => 'en', 'full_name' => 'Changed'])->save();

        self::assertSame(3, $snapshot->matched_count);
        self::assertSame(1, $snapshot->eligible_count);
        self::assertSame(2, $snapshot->suppressed_count);
        self::assertSame([$eligible->id], BroadcastRecipient::query()->where('campaign_id', $campaign->id)->where('state', BroadcastRecipientState::Pending->value)->pluck('client_id')->all());
        self::assertSame('ru', BroadcastRecipient::query()->where('client_id', $eligible->id)->value('language'));
    }

    public function test_cancellation_and_schedule_race_has_one_valid_terminal_path(): void
    {
        $this->requirePostgres();
        [$organization, $actor, $campaign] = $this->fixture();
        $campaign->forceFill(['state' => BroadcastCampaignState::Scheduled, 'scheduled_at' => now()->subSecond()])->save();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::cancelInProcess($organization->id, $actor->id, $campaign->id),
            static fn (): string => self::scheduleInProcess() === 1 ? 'scheduled' : 'not_claimed',
        ]);
        $state = $campaign->refresh()->state;

        self::assertContains($state, [BroadcastCampaignState::Cancelled, BroadcastCampaignState::Completed]);
        self::assertTrue(in_array('cancelled', $results, true) xor in_array('scheduled', $results, true));
        self::assertSame(0, DB::table('broadcast_delivery_attempts')->count());
    }

    public function test_postgresql_identifiers_and_composite_tenant_constraints_are_safe(): void
    {
        $this->requirePostgres();
        $identifiers = DB::select("SELECT relname AS name FROM pg_class WHERE relnamespace = current_schema()::regnamespace AND relname LIKE 'bc_%' UNION SELECT conname AS name FROM pg_constraint WHERE connamespace = current_schema()::regnamespace AND conname LIKE 'bc_%'");
        $names = array_map(static fn (object $row): string => (string) data_get($row, 'name'), $identifiers);
        self::assertNotEmpty($names);
        $maxLength = 0;
        foreach ($names as $name) {
            $maxLength = max($maxLength, strlen($name));
        }
        self::assertLessThanOrEqual(63, $maxLength);
        self::assertSame(count($names), count(array_unique(array_map(static fn (string $name): string => substr($name, 0, 63), $names))));
    }

    public function test_postgresql_composite_foreign_keys_reject_cross_tenant_broadcast_rows(): void
    {
        $this->requirePostgres();
        [$organizationA, $actorA, $campaignA] = $this->fixture();
        $clientA = $this->eligibleClient($organizationA);
        $snapshotA = app(MaterializeBroadcastAudience::class)->handle($campaignA);
        $batchA = BroadcastBatch::query()->where('snapshot_id', $snapshotA->getKey())->sole();
        $recipientA = BroadcastRecipient::query()->where('snapshot_id', $snapshotA->getKey())->sole();

        [$organizationB, $actorB, $campaignB] = $this->fixture();
        $clientB = $this->eligibleClient($organizationB);
        $snapshotB = app(MaterializeBroadcastAudience::class)->handle($campaignB);
        $batchB = BroadcastBatch::query()->where('snapshot_id', $snapshotB->getKey())->sole();
        $recipientB = BroadcastRecipient::query()->where('snapshot_id', $snapshotB->getKey())->sole();
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        $attemptTimestamps = ['created_at' => now()];

        $this->assertConstraintRejects(function () use ($organizationA, $campaignB, $snapshotB, $timestamps): void {
            DB::table('broadcast_audience_snapshots')->insert([
                'organization_id' => $organizationA->getKey(),
                'campaign_id' => $campaignB->getKey(),
                'version' => 99,
                'draft_version' => 1,
                'segment_definition' => json_encode([], JSON_THROW_ON_ERROR),
                'segment_summary' => 'invalid',
                'channel_priority' => json_encode(['telegram'], JSON_THROW_ON_ERROR),
                'template_version_ru_id' => $snapshotB->template_version_ru_id,
                'matched_count' => 0,
                'eligible_count' => 0,
                'suppressed_count' => 0,
                'materialized_at' => now(),
                ...$timestamps,
            ]);
        });

        $this->assertConstraintRejects(function () use ($organizationA, $campaignA, $snapshotB, $timestamps): void {
            DB::table('broadcast_audience_snapshots')->insert([
                'organization_id' => $organizationA->getKey(),
                'campaign_id' => $campaignA->getKey(),
                'version' => 99,
                'draft_version' => 1,
                'segment_definition' => json_encode([], JSON_THROW_ON_ERROR),
                'segment_summary' => 'invalid',
                'channel_priority' => json_encode(['telegram'], JSON_THROW_ON_ERROR),
                'template_version_ru_id' => $snapshotB->template_version_ru_id,
                'matched_count' => 0,
                'eligible_count' => 0,
                'suppressed_count' => 0,
                'materialized_at' => now(),
                ...$timestamps,
            ]);
        });

        $this->assertConstraintRejects(function () use ($organizationA, $campaignA, $snapshotA, $batchA, $clientB, $timestamps): void {
            DB::table('broadcast_recipients')->insert([
                'organization_id' => $organizationA->getKey(),
                'campaign_id' => $campaignA->getKey(),
                'snapshot_id' => $snapshotA->getKey(),
                'batch_id' => $batchA->getKey(),
                'client_id' => $clientB->getKey(),
                'kind' => 'production',
                'language' => 'ru',
                'channel' => 'telegram',
                'external_id' => 'foreign',
                'render_context' => json_encode([], JSON_THROW_ON_ERROR),
                'state' => 'pending',
                'idempotency_key' => hash('sha256', 'foreign-client'),
                'attempt_count' => 0,
                ...$timestamps,
            ]);
        });

        $this->assertConstraintRejects(function () use ($organizationA, $campaignA, $snapshotB, $timestamps): void {
            DB::table('broadcast_batches')->insert([
                'organization_id' => $organizationA->getKey(),
                'campaign_id' => $campaignA->getKey(),
                'snapshot_id' => $snapshotB->getKey(),
                'sequence' => 99,
                'state' => 'pending',
                'available_at' => now(),
                ...$timestamps,
            ]);
        });

        $this->assertConstraintRejects(function () use ($organizationA, $campaignA, $snapshotA, $batchB, $clientA, $timestamps): void {
            DB::table('broadcast_recipients')->insert([
                'organization_id' => $organizationA->getKey(),
                'campaign_id' => $campaignA->getKey(),
                'snapshot_id' => $snapshotA->getKey(),
                'batch_id' => $batchB->getKey(),
                'client_id' => $clientA->getKey(),
                'kind' => 'production',
                'language' => 'ru',
                'channel' => 'telegram',
                'external_id' => 'foreign-batch',
                'render_context' => json_encode([], JSON_THROW_ON_ERROR),
                'state' => 'pending',
                'idempotency_key' => hash('sha256', 'foreign-batch'),
                'attempt_count' => 0,
                ...$timestamps,
            ]);
        });

        $this->assertConstraintRejects(function () use ($organizationA, $campaignB, $snapshotB, $batchB, $recipientB, $attemptTimestamps): void {
            DB::table('broadcast_delivery_attempts')->insert([
                'organization_id' => $organizationA->getKey(),
                'recipient_id' => $recipientB->getKey(),
                'campaign_id' => $campaignB->getKey(),
                'snapshot_id' => $snapshotB->getKey(),
                'batch_id' => $batchB->getKey(),
                'channel' => 'telegram',
                'idempotency_key' => hash('sha256', 'foreign-attempt'),
                'attempt_number' => 1,
                'outcome' => 'unknown',
                'started_at' => now(),
                'attempted_at' => now(),
                ...$attemptTimestamps,
            ]);
        });

        $this->assertConstraintRejects(function () use ($organizationA, $campaignB, $snapshotB, $batchB, $recipientA, $attemptTimestamps): void {
            DB::table('broadcast_delivery_attempts')->insert([
                'organization_id' => $organizationA->getKey(),
                'recipient_id' => $recipientA->getKey(),
                'campaign_id' => $campaignB->getKey(),
                'snapshot_id' => $snapshotB->getKey(),
                'batch_id' => $batchB->getKey(),
                'channel' => 'telegram',
                'idempotency_key' => hash('sha256', 'foreign-attempt-scope'),
                'attempt_number' => 1,
                'outcome' => 'unknown',
                'started_at' => now(),
                'attempted_at' => now(),
                ...$attemptTimestamps,
            ]);
        });
    }

    public function test_dispatching_campaign_and_due_batch_are_recovered_after_scheduler_wakeup(): void
    {
        $this->requirePostgres();
        Queue::fake();
        [$organization, $actor, $campaign] = $this->fixture();
        $this->eligibleClient($organization);
        $snapshot = app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill([
            'state' => BroadcastCampaignState::Dispatching,
            'scheduled_at' => null,
            'dispatch_started_at' => now()->subMinutes(10),
            'next_dispatch_at' => null,
        ])->save();
        $batch = BroadcastBatch::query()->where('snapshot_id', $snapshot->getKey())->sole();

        $result = app(ScheduleBroadcastWork::class)->handle();

        self::assertSame(1, $result['campaigns']);
        self::assertSame(1, $result['batches']);
        self::assertSame(1, $batch->refresh()->dispatch_attempt_count);
        Queue::assertPushed(\App\Modules\Broadcasts\Jobs\ProcessBroadcastBatch::class, 1);
    }

    public function test_partial_batch_enqueue_is_recovered_with_bounded_dispatch_backoff(): void
    {
        $this->requirePostgres();
        [$organization, $actor, $campaign] = $this->fixture();
        foreach (range(1, 205) as $index) {
            $this->eligibleClient($organization);
        }
        $snapshot = app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'scheduled_at' => now(), 'dispatch_started_at' => now()])->save();

        $dispatches = 0;
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (mixed $job) use (&$dispatches): mixed {
            $dispatches++;
            if ($dispatches === 2) {
                throw new \RuntimeException('queue unavailable');
            }

            return $job;
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $first = app(ScheduleBroadcastWork::class)->handle();
        self::assertSame(2, $first['batches']);
        self::assertSame(3, BroadcastBatch::query()->where('snapshot_id', $snapshot->getKey())->count());
        $failed = BroadcastBatch::query()->where('last_dispatch_error_code', 'queue_dispatch_failed')->sole();
        self::assertSame('pending', $failed->state);
        self::assertSame(1, $failed->dispatch_attempt_count);

        $campaign->forceFill(['next_dispatch_at' => null])->save();
        $failed->forceFill(['available_at' => now()->subMinute()])->save();
        $second = app(ScheduleBroadcastWork::class)->handle();

        self::assertSame(1, $second['batches']);
        self::assertSame(4, $dispatches);
        self::assertSame(2, $failed->refresh()->dispatch_attempt_count);
        self::assertSame('pending', $failed->state);
    }

    public function test_lost_batch_job_is_rediscovered_by_database_state(): void
    {
        $this->requirePostgres();
        Queue::fake();
        [$organization, $actor, $campaign] = $this->fixture();
        $this->eligibleClient($organization);
        $snapshot = app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'scheduled_at' => now(), 'dispatch_started_at' => now()])->save();
        $batch = BroadcastBatch::query()->where('snapshot_id', $snapshot->getKey())->sole();

        self::assertSame(1, app(ScheduleBroadcastWork::class)->handle()['batches']);
        $batch->forceFill(['available_at' => now()->subMinute()])->save();
        $campaign->forceFill(['next_dispatch_at' => null])->save();
        self::assertSame(1, app(ScheduleBroadcastWork::class)->handle()['batches']);
        self::assertSame(2, $batch->refresh()->dispatch_attempt_count);
        Queue::assertPushed(\App\Modules\Broadcasts\Jobs\ProcessBroadcastBatch::class, 2);
    }

    public function test_repeated_scheduler_runs_do_not_duplicate_a_reserved_batch_and_terminal_campaigns_are_not_reclaimed(): void
    {
        $this->requirePostgres();
        Queue::fake();
        [$organization, $actor, $campaign] = $this->fixture();
        $this->eligibleClient($organization);
        $snapshot = app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'scheduled_at' => now(), 'dispatch_started_at' => now()])->save();
        $batch = BroadcastBatch::query()->where('snapshot_id', $snapshot->getKey())->sole();

        self::assertSame(1, app(ScheduleBroadcastWork::class)->handle()['batches']);
        self::assertSame(0, app(ScheduleBroadcastWork::class)->handle()['batches']);
        self::assertSame(1, $batch->refresh()->dispatch_attempt_count);

        $campaign->forceFill(['state' => BroadcastCampaignState::Cancelled, 'cancelled_at' => now(), 'next_dispatch_at' => null])->save();
        $batch->forceFill(['available_at' => now()->subMinute(), 'state' => 'pending'])->save();
        self::assertSame(0, app(ScheduleBroadcastWork::class)->handle()['campaigns']);

        $campaign->forceFill(['state' => BroadcastCampaignState::Completed, 'completed_at' => now()])->save();
        $batch->forceFill(['available_at' => now()->subMinute(), 'state' => 'pending'])->save();
        self::assertSame(0, app(ScheduleBroadcastWork::class)->handle()['campaigns']);
    }

    public function test_stale_claim_recovery_fences_old_attempt_and_reclaims_only_once(): void
    {
        $this->requirePostgres();
        Queue::fake();
        [$organization, $actor, $campaign] = $this->fixture();
        $this->eligibleClient($organization);
        $snapshot = app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'scheduled_at' => now(), 'dispatch_started_at' => now()])->save();
        $batch = BroadcastBatch::query()->where('snapshot_id', $snapshot->getKey())->sole();
        $batch->forceFill(['state' => 'claimed', 'lease_token' => (string) Str::uuid(), 'claimed_at' => now()->subMinutes(10), 'available_at' => now()->subMinutes(10)])->save();

        self::assertSame(1, app(ScheduleBroadcastWork::class)->handle()['batches']);
        self::assertSame(1, $batch->refresh()->dispatch_attempt_count);
        self::assertSame('claimed', $batch->state);
    }

    public function test_failed_old_job_cannot_overwrite_a_reclaimed_batch_lease(): void
    {
        $this->requirePostgres();
        [$organization, $actor, $campaign] = $this->fixture();
        $this->eligibleClient($organization);
        $snapshot = app(MaterializeBroadcastAudience::class)->handle($campaign);
        $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'scheduled_at' => now(), 'dispatch_started_at' => now()])->save();
        $batch = BroadcastBatch::query()->where('snapshot_id', $snapshot->getKey())->sole();
        $recipient = BroadcastRecipient::query()->where('batch_id', $batch->getKey())->sole();
        $lease = (string) Str::uuid();
        $recipient->forceFill(['state' => BroadcastRecipientState::Claimed, 'lease_token' => $lease, 'claimed_at' => now(), 'attempt_count' => 1])->save();
        $batch->forceFill(['state' => 'claimed', 'lease_token' => $lease, 'claimed_at' => now()])->save();
        BroadcastDeliveryAttempt::query()->create([
            'organization_id' => $organization->getKey(),
            'recipient_id' => $recipient->getKey(),
            'campaign_id' => $campaign->getKey(),
            'snapshot_id' => $snapshot->getKey(),
            'batch_id' => $batch->getKey(),
            'channel' => 'telegram',
            'idempotency_key' => $recipient->idempotency_key,
            'attempt_number' => 1,
            'outcome' => 'in_flight',
            'started_at' => now(),
            'attempted_at' => now(),
        ]);

        app(ProcessBroadcastBatch::class)->markJobFailed($organization->getKey(), $batch->getKey(), 'queue_job_failed', 'stale-lease');
        self::assertSame(BroadcastRecipientState::Claimed, $recipient->refresh()->state);
        self::assertSame('in_flight', BroadcastDeliveryAttempt::query()->sole()->outcome->value);

        app(ProcessBroadcastBatch::class)->markJobFailed($organization->getKey(), $batch->getKey(), 'queue_job_failed', $lease);
        self::assertSame(BroadcastRecipientState::Failed, $recipient->refresh()->state);
        self::assertSame('unknown', BroadcastDeliveryAttempt::query()->sole()->outcome->value);
    }

    public function test_materialization_and_draft_update_converge_to_one_launchable_revision(): void
    {
        $this->requirePostgres();
        [$organization, $actor, $campaign] = $this->fixture();
        $client = $this->eligibleClient($organization);
        $results = Concurrency::driver('process')->run([
            static fn (): int => self::materializeInProcess($campaign->getKey()),
            static fn (): int => self::updateInProcess($organization->getKey(), $actor->getKey(), $campaign->getKey()),
        ]);

        $campaign = $campaign->refresh();
        self::assertSame(2, (int) $campaign->draft_version);
        if ($campaign->audience_snapshot_id !== null) {
            self::assertSame(2, (int) DB::table('broadcast_audience_snapshots')->where('id', $campaign->audience_snapshot_id)->value('draft_version'));
        }
        self::assertGreaterThanOrEqual(1, count($results));
        self::assertSame($organization->getKey(), $client->refresh()->organization_id);
    }

    public function test_test_send_and_start_race_never_sends_a_test_recipient_after_start_wins(): void
    {
        $this->requirePostgres();
        [$organization, $actor, $campaign] = $this->fixture();
        $client = $this->eligibleClient($organization);
        $results = Concurrency::driver('process')->run([
            static fn (): string => self::test_send_in_process($organization->getKey(), $actor->getKey(), $campaign->getKey(), $client->getKey()),
            static fn (): string => self::startInProcess($organization->getKey(), $actor->getKey(), $campaign->getKey()),
        ]);

        $campaign->refresh();
        $testRecipients = BroadcastRecipient::query()->where('campaign_id', $campaign->getKey())->where('kind', 'test')->get();
        self::assertLessThanOrEqual(1, $testRecipients->count());
        if ($campaign->state !== BroadcastCampaignState::Draft) {
            foreach ($testRecipients as $recipient) {
                self::assertContains($recipient->state, [BroadcastRecipientState::Delivered, BroadcastRecipientState::Failed]);
                if ($recipient->state === BroadcastRecipientState::Failed) {
                    self::assertSame('campaign_state_changed', $recipient->last_error_code);
                }
            }
        }
        self::assertCount(2, $results);
    }

    public function test_concurrent_classification_replacements_are_serialized_and_canonical(): void
    {
        $this->requirePostgres();
        [$organization, $actor] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'ru']);

        Concurrency::driver('process')->run([
            static fn (): int => self::classifyInProcess($organization->getKey(), $actor->getKey(), $client->getKey(), [' VIP ']),
            static fn (): int => self::classifyInProcess($organization->getKey(), $actor->getKey(), $client->getKey(), ['Beta']),
        ]);

        $tags = BroadcastClientTag::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->pluck('tag')
            ->all();
        self::assertTrue(in_array($tags, [['vip'], ['beta']], true));
    }

    private function assertConstraintRejects(Closure $operation): void
    {
        DB::beginTransaction();
        try {
            $operation();
        } catch (QueryException) {
            DB::rollBack();

            return;
        }
        DB::rollBack();
        self::fail('PostgreSQL composite constraint accepted an invalid cross-tenant row.');
    }

    /** @return array{Organization, User, BroadcastCampaign} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $actor = User::factory()->forOrganization($organization)->create();
        $template = NotificationTemplate::factory()->forOrganization($organization)->create(['purpose' => ScenarioRulePurpose::Marketing->value, 'locale' => 'ru']);
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create();
        $campaign = new BroadcastCampaign;
        $campaign->forceFill(['organization_id' => $organization->id, 'created_by_user_id' => $actor->id, 'name' => 'PG broadcast', 'state' => BroadcastCampaignState::Draft, 'send_mode' => 'immediate', 'channel_priority' => ['telegram'], 'segment_definition' => [], 'segment_summary' => 'Все подходящие клиенты организации', 'template_version_ru_id' => $version->id, 'draft_version' => 1])->save();

        return [$organization, $actor, $campaign->refresh()];
    }

    private function eligibleClient(Organization $organization): Client
    {
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'ru']);
        ClientConsent::factory()->forClient($client)->create(['subject' => ConsentSubject::Marketing->value, 'is_required' => false, 'granted' => true]);
        ClientChannelIdentity::factory()->forClient($client)->create(['channel' => 'telegram', 'external_id' => 'pg-chat-'.$client->id, 'verification_status' => ChannelIdentityStatus::Verified->value, 'verification_method' => 'test', 'verified_at' => now()]);

        return $client;
    }

    private static function scheduleInProcess(): int
    {
        Queue::fake();

        return app(ScheduleBroadcastWork::class)->handle()['campaigns'];
    }

    private static function materializeInProcess(int $campaignId): int
    {
        return app(MaterializeBroadcastAudience::class)->handle(BroadcastCampaign::query()->findOrFail($campaignId))->getKey();
    }

    private static function updateInProcess(int $organizationId, int $actorId, int $campaignId): int
    {
        $organization = Organization::query()->findOrFail($organizationId);
        app(OrganizationContext::class)->set($organization);
        $campaign = BroadcastCampaign::query()->where('organization_id', $organizationId)->findOrFail($campaignId);

        return app(UpdateBroadcastCampaign::class)->handle(User::query()->findOrFail($actorId), $campaign, [
            'name' => 'PG updated broadcast',
            'send_mode' => 'immediate',
            'channel_priority' => ['telegram'],
            'segment_definition' => $campaign->segment_definition,
            'template_version_ru_id' => $campaign->template_version_ru_id,
            'template_version_en_id' => $campaign->template_version_en_id,
            'scheduled_at' => null,
        ])->draft_version;
    }

    private static function test_send_in_process(int $organizationId, int $actorId, int $campaignId, int $clientId): string
    {
        $organization = Organization::query()->findOrFail($organizationId);
        app(OrganizationContext::class)->set($organization);
        app()->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([new RecordingNotificationChannel]));
        try {
            app(TestBroadcastCampaign::class)->handle(User::query()->findOrFail($actorId), BroadcastCampaign::query()->findOrFail($campaignId), $clientId);

            return 'test_sent';
        } catch (ValidationException) {
            return 'test_rejected';
        }
    }

    private static function startInProcess(int $organizationId, int $actorId, int $campaignId): string
    {
        $organization = Organization::query()->findOrFail($organizationId);
        app(OrganizationContext::class)->set($organization);
        app()->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([new RecordingNotificationChannel]));
        try {
            app(StartBroadcastCampaign::class)->handle(User::query()->findOrFail($actorId), BroadcastCampaign::query()->findOrFail($campaignId));

            return 'started';
        } catch (ValidationException) {
            return 'start_rejected';
        }
    }

    private static function processBatchInProcess(int $organizationId, int $batchId): int
    {
        $channel = new RecordingNotificationChannel;
        app()->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));
        app(ProcessBroadcastBatch::class)->handle($organizationId, $batchId);

        return count($channel->messages);
    }

    /** @param list<string> $tags */
    private static function classifyInProcess(int $organizationId, int $actorId, int $clientId, array $tags): int
    {
        $organization = Organization::query()->findOrFail($organizationId);
        app(OrganizationContext::class)->set($organization);
        app(SetBroadcastClientClassification::class)->handle(
            User::query()->findOrFail($actorId),
            Client::query()->where('organization_id', $organizationId)->findOrFail($clientId),
            null,
            $tags,
        );

        return 1;
    }

    private static function cancelInProcess(int $organizationId, int $actorId, int $campaignId): string
    {
        $organization = Organization::query()->findOrFail($organizationId);
        app(OrganizationContext::class)->set($organization);
        try {
            app(CancelBroadcastCampaign::class)->handle(User::query()->findOrFail($actorId), BroadcastCampaign::query()->findOrFail($campaignId));

            return 'cancelled';
        } catch (ValidationException) {
            return 'not_cancelled';
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('M11B concurrency and constraint coverage requires PostgreSQL.');
        }
    }
}
