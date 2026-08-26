<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Broadcasts\Application\CancelBroadcastCampaign;
use App\Modules\Broadcasts\Application\MaterializeBroadcastAudience;
use App\Modules\Broadcasts\Application\ProcessBroadcastBatch;
use App\Modules\Broadcasts\Application\ScheduleBroadcastWork;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
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
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        $campaign->forceFill(['state' => BroadcastCampaignState::Scheduled, 'scheduled_at' => now()->subMinute()])->save();

        $results = Concurrency::driver('process')->run([
            static fn (): int => self::scheduleInProcess(),
            static fn (): int => self::scheduleInProcess(),
        ]);

        self::assertSame(1, array_sum($results));
        self::assertSame(BroadcastCampaignState::Completed, $campaign->refresh()->state);
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

    private static function processBatchInProcess(int $organizationId, int $batchId): int
    {
        $channel = new RecordingNotificationChannel;
        app()->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));
        app(ProcessBroadcastBatch::class)->handle($organizationId, $batchId);

        return count($channel->messages);
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
