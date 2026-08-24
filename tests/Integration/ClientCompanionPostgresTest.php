<?php

namespace Tests\Integration;

use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Services\CompanionTurnProcessor;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnMessage;
use App\Modules\Conversations\Application\AdoptLegacyCompanionConversations;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationBinding;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClientCompanionPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_concurrent_portal_retries_create_one_turn_and_one_message(): void
    {
        $this->requirePostgres('Companion idempotency concurrency requires PostgreSQL.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $idempotencyKey = 'pg-companion-'.Str::uuid();

        $results = Concurrency::driver('process')->run([
            fn (): array => self::acceptPortalMessage($organization->id, $client->id, $idempotencyKey),
            fn (): array => self::acceptPortalMessage($organization->id, $client->id, $idempotencyKey),
        ]);

        self::assertSame($results[0]['turn_id'], $results[1]['turn_id']);
        self::assertSame(1, CompanionTurn::query()
            ->where('organization_id', $organization->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count());
        self::assertSame(1, DB::table('conversation_messages')
            ->where('organization_id', $organization->id)
            ->where('external_id', 'portal:'.$idempotencyKey)
            ->count());
        self::assertSame(1, CompanionTurnMessage::query()
            ->where('organization_id', $organization->id)
            ->where('turn_id', $results[0]['turn_id'])
            ->count());
    }

    public function test_concurrent_media_groups_preserve_one_conversation_sequence(): void
    {
        $this->requirePostgres('Companion ordering concurrency requires PostgreSQL row locks.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();

        $results = Concurrency::driver('process')->run([
            fn (): array => self::acceptTelegramMessage($organization->id, $client->id, 101, 'album-a', 'first'),
            fn (): array => self::acceptTelegramMessage($organization->id, $client->id, 102, 'album-b', 'second'),
        ]);

        self::assertCount(2, CompanionTurn::query()
            ->where('organization_id', $organization->id)
            ->where('client_id', $client->id)
            ->get());
        self::assertSame([1, 2], CompanionTurn::query()
            ->where('organization_id', $organization->id)
            ->where('client_id', $client->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all());
        self::assertCount(2, array_unique(array_column($results, 'turn_id')));
    }

    public function test_concurrent_items_for_one_media_group_create_one_durable_assembling_turn(): void
    {
        $this->requirePostgres('Companion album assembly concurrency requires PostgreSQL row locks.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();

        $results = Concurrency::driver('process')->run([
            fn (): array => self::acceptTelegramMessage($organization->id, $client->id, 201, 'same-album', 'first'),
            fn (): array => self::acceptTelegramMessage($organization->id, $client->id, 202, 'same-album', 'second'),
        ]);

        self::assertSame($results[0]['turn_id'], $results[1]['turn_id']);
        self::assertSame(1, CompanionTurn::query()->where('organization_id', $organization->id)->where('client_id', $client->id)->count());
        self::assertSame(2, CompanionTurnMessage::query()->where('organization_id', $organization->id)->where('turn_id', $results[0]['turn_id'])->count());
        self::assertSame(CompanionTurnStatus::Assembling, CompanionTurn::query()->findOrFail($results[0]['turn_id'])->status);
    }

    public function test_postgres_legacy_adoption_preserves_tenant_keyed_history_and_fails_closed_on_ambiguity(): void
    {
        $this->requirePostgres('Legacy Companion adoption requires PostgreSQL migration/tenant evidence.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $legacy = new Conversation;
        $legacy->forceFill([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'channel' => 'telegram',
            'external_key' => 'pg-legacy-chat',
            'conversation_type' => ConversationType::Channel,
            'started_at' => now()->subMinute(),
        ]);
        $legacy->save();
        $message = new ConversationMessage;
        $message->forceFill([
            'organization_id' => $organization->id,
            'conversation_id' => $legacy->id,
            'client_id' => $client->id,
            'channel' => 'telegram',
            'direction' => ConversationDirection::Inbound,
            'author_type' => ConversationAuthorType::Client,
            'external_id' => 'pg-legacy-message',
            'body' => 'Старое сообщение',
            'metadata' => ['source' => 'm2'],
            'occurred_at' => now()->subMinute(),
        ]);
        $message->save();

        app(AdoptLegacyCompanionConversations::class)->handle();

        $companion = Conversation::query()->where('organization_id', $organization->id)->where('client_id', $client->id)->where('conversation_type', ConversationType::ClientCompanion)->sole();
        self::assertSame($companion->id, $message->refresh()->conversation_id);
        self::assertSame($companion->id, ConversationBinding::query()->where('organization_id', $organization->id)->sole()->conversation_id);
        self::assertNull($message->body);
        self::assertNotNull($message->encrypted_body);

        $ambiguous = new Conversation;
        $ambiguous->forceFill([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'channel' => 'portal',
            'external_key' => 'Display Name',
            'conversation_type' => ConversationType::Channel,
            'started_at' => now(),
        ]);
        $ambiguous->save();

        $stats = app(AdoptLegacyCompanionConversations::class)->handle();

        self::assertSame(1, $stats['ambiguous']);
        self::assertSame(ConversationType::Channel, $ambiguous->refresh()->conversation_type);
        self::assertSame(1, Conversation::query()->where('organization_id', $organization->id)->where('client_id', $client->id)->where('conversation_type', ConversationType::ClientCompanion)->count());
    }

    public function test_postgres_adoption_and_live_telegram_message_share_one_binding_and_history(): void
    {
        $this->requirePostgres('Adoption/live Telegram concurrency requires PostgreSQL advisory and row locks.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $legacy = $this->createLegacyConversation($organization, $client, 'telegram', 'pg-live-telegram', 'old-telegram', 'Старое Telegram сообщение');

        $results = Concurrency::driver('process')->run([
            static fn (): array => app(AdoptLegacyCompanionConversations::class)->handle(),
            fn (): array => self::acceptTelegramMessage($organization->id, $client->id, 301, 'pg-live-telegram', 'Новое Telegram сообщение', 'pg-live-telegram'),
        ]);

        self::assertCount(2, $results);
        $companion = Conversation::query()
            ->where('organization_id', $organization->id)
            ->where('client_id', $client->id)
            ->where('conversation_type', ConversationType::ClientCompanion)
            ->sole();
        self::assertSame($companion->id, ConversationMessage::query()->findOrFail($legacy['message_id'])->conversation_id);
        self::assertSame(2, ConversationMessage::query()->where('organization_id', $organization->id)->where('conversation_id', $companion->id)->count());
        self::assertSame(1, ConversationBinding::query()->where('organization_id', $organization->id)->count());
        self::assertSame($companion->id, ConversationBinding::query()->where('organization_id', $organization->id)->sole()->conversation_id);
        self::assertNull(ConversationMessage::query()->findOrFail($legacy['message_id'])->body);
        self::assertNotNull(ConversationMessage::query()->findOrFail($legacy['message_id'])->encrypted_body);
    }

    public function test_postgres_adoption_and_live_portal_binding_creation_are_idempotent(): void
    {
        $this->requirePostgres('Adoption/Portal binding concurrency requires PostgreSQL advisory and row locks.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $legacy = $this->createLegacyConversation($organization, $client, 'portal', 'client:'.$client->id, 'old-portal', 'Старое сообщение портала');

        Concurrency::driver('process')->run([
            static fn (): array => app(AdoptLegacyCompanionConversations::class)->handle(),
            fn (): array => self::acceptPortalMessage($organization->id, $client->id, 'pg-live-portal-'.Str::uuid()),
        ]);

        $companion = Conversation::query()
            ->where('organization_id', $organization->id)
            ->where('client_id', $client->id)
            ->where('conversation_type', ConversationType::ClientCompanion)
            ->sole();
        self::assertSame($companion->id, ConversationMessage::query()->findOrFail($legacy['message_id'])->conversation_id);
        self::assertSame(2, ConversationMessage::query()->where('organization_id', $organization->id)->where('conversation_id', $companion->id)->count());
        self::assertSame(1, ConversationBinding::query()->where('organization_id', $organization->id)->where('channel', 'portal')->count());
        self::assertSame($companion->id, ConversationBinding::query()->where('organization_id', $organization->id)->sole()->conversation_id);
    }

    public function test_postgres_two_adoption_workers_are_idempotent_for_one_legacy_conversation(): void
    {
        $this->requirePostgres('Concurrent adoption requires PostgreSQL advisory and row locks.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $legacy = $this->createLegacyConversation($organization, $client, 'telegram', 'pg-two-adopters', 'two-adopter-message', 'Один раз сохранить');

        Concurrency::driver('process')->run([
            static fn (): array => app(AdoptLegacyCompanionConversations::class)->handle(),
            static fn (): array => app(AdoptLegacyCompanionConversations::class)->handle(),
        ]);

        self::assertSame(1, Conversation::query()->where('organization_id', $organization->id)->where('client_id', $client->id)->where('conversation_type', ConversationType::ClientCompanion)->count());
        self::assertSame(1, ConversationBinding::query()->where('organization_id', $organization->id)->count());
        self::assertSame(1, ConversationMessage::query()->where('organization_id', $organization->id)->count());
        self::assertNull(ConversationMessage::query()->findOrFail($legacy['message_id'])->body);
    }

    public function test_postgres_adoption_with_existing_target_and_live_message_keeps_one_canonical_conversation(): void
    {
        $this->requirePostgres('Adoption with an existing target requires PostgreSQL row locks.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $existing = self::acceptPortalMessage($organization->id, $client->id, 'pg-existing-target-'.Str::uuid());
        $legacy = $this->createLegacyConversation($organization, $client, 'telegram', 'pg-existing-telegram', 'existing-target-old', 'История до цели');

        Concurrency::driver('process')->run([
            static fn (): array => app(AdoptLegacyCompanionConversations::class)->handle(),
            fn (): array => self::acceptTelegramMessage($organization->id, $client->id, 302, 'pg-existing-telegram', 'Новое после цели', 'pg-existing-telegram'),
        ]);

        $companion = Conversation::query()
            ->where('organization_id', $organization->id)
            ->where('client_id', $client->id)
            ->where('conversation_type', ConversationType::ClientCompanion)
            ->sole();
        self::assertSame($companion->id, CompanionTurn::query()->findOrFail($existing['turn_id'])->conversation_id);
        self::assertSame($companion->id, ConversationMessage::query()->findOrFail($legacy['message_id'])->conversation_id);
        self::assertSame(3, ConversationMessage::query()->where('organization_id', $organization->id)->where('conversation_id', $companion->id)->count());
        self::assertSame(2, ConversationBinding::query()->where('organization_id', $organization->id)->count());
    }

    public function test_postgres_same_client_channel_adoptions_and_different_clients_remain_isolated(): void
    {
        $this->requirePostgres('Per-client adoption serialization requires PostgreSQL advisory locks.');

        $organization = Organization::factory()->create();
        $clientA = Client::factory()->forOrganization($organization)->create();
        $clientB = Client::factory()->forOrganization($organization)->create();
        $legacyTelegram = $this->createLegacyConversation($organization, $clientA, 'telegram', 'pg-client-a-telegram', 'client-a-telegram', 'A Telegram');
        $legacyPortal = $this->createLegacyConversation($organization, $clientA, 'portal', 'client:'.$clientA->id, 'client-a-portal', 'A Portal');
        $legacyB = $this->createLegacyConversation($organization, $clientB, 'telegram', 'pg-client-b-telegram', 'client-b-telegram', 'B Telegram');

        Concurrency::driver('process')->run([
            static fn (): array => app(AdoptLegacyCompanionConversations::class)->handle(),
            static fn (): array => app(AdoptLegacyCompanionConversations::class)->handle(),
            static fn (): array => app(AdoptLegacyCompanionConversations::class)->handle(),
        ]);

        self::assertSame(2, Conversation::query()->where('organization_id', $organization->id)->where('conversation_type', ConversationType::ClientCompanion)->count());
        self::assertSame(3, ConversationBinding::query()->where('organization_id', $organization->id)->count());
        foreach ([$legacyTelegram, $legacyPortal, $legacyB] as $legacy) {
            self::assertNull(ConversationMessage::query()->findOrFail($legacy['message_id'])->body);
            self::assertNotNull(ConversationMessage::query()->findOrFail($legacy['message_id'])->encrypted_body);
        }
        self::assertSame(1, Conversation::query()->where('organization_id', $organization->id)->where('client_id', $clientA->id)->where('conversation_type', ConversationType::ClientCompanion)->count());
        self::assertSame(1, Conversation::query()->where('organization_id', $organization->id)->where('client_id', $clientB->id)->where('conversation_type', ConversationType::ClientCompanion)->count());
    }

    public function test_postgres_stale_companion_completion_cannot_publish_after_lease_replacement(): void
    {
        $this->requirePostgres('Companion terminal fencing requires PostgreSQL row locks.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);
        Queue::fake();
        $turn = app(AcceptCompanionMessage::class)->handle(
            client: $client,
            channel: 'portal',
            body: 'PG stale completion',
            idempotencyKey: 'pg-stale-completion-'.Str::uuid(),
            originExternalId: 'portal:pg-stale-completion',
            locale: 'en',
        );
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new PostgresInterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Не публиковать', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            fn (): mixed => CompanionTurn::query()->whereKey($turn->getKey())->update([
                'processing_lease_token' => 'worker-b-token',
                'processing_lease_expires_at' => now()->addMinutes(5),
            ]),
        ));
        $this->app->instance(MessagingChannel::class, new PostgresCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($organization->id, $turn->id);

        self::assertSame(CompanionTurnStatus::Processing, $turn->refresh()->status);
        self::assertSame('worker-b-token', $turn->processing_lease_token);
        self::assertNull($turn->outbound_message_id);
        self::assertSame(0, ConversationMessage::query()->where('organization_id', $organization->id)->where('author_type', 'ai')->count());
    }

    public function test_companion_turn_composite_tenant_foreign_key_rejects_foreign_client(): void
    {
        $this->requirePostgres('Companion tenant constraints require PostgreSQL composite foreign keys.');

        Queue::fake();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        $turn = app(AcceptCompanionMessage::class)->handle(
            client: $client,
            channel: 'portal',
            body: 'Проверка границы организации',
            idempotencyKey: 'pg-tenant-boundary-'.Str::uuid(),
            originExternalId: 'portal:tenant-boundary',
            locale: 'ru',
        );
        $turn->forceFill(['client_id' => $otherClient->id]);

        $this->expectException(QueryException::class);
        $turn->save();
    }

    private function requirePostgres(string $message): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped($message);
        }
    }

    /** @return array{turn_id: int, message_id: int} */
    private static function acceptPortalMessage(int $organizationId, int $clientId, string $idempotencyKey): array
    {
        Queue::fake();
        $organization = Organization::query()->findOrFail($organizationId);
        config()->set('tenancy.default_organization_id', $organizationId);
        app(OrganizationContext::class)->set($organization);
        $turn = app(AcceptCompanionMessage::class)->handle(
            client: Client::query()->findOrFail($clientId),
            channel: 'portal',
            body: 'Повторная отправка',
            idempotencyKey: $idempotencyKey,
            originExternalId: 'portal:'.$idempotencyKey,
            locale: 'ru',
        );

        return ['turn_id' => (int) $turn->getKey(), 'message_id' => (int) $turn->inbound_message_id];
    }

    /** @return array{turn_id: int, message_id: int} */
    private static function acceptTelegramMessage(
        int $organizationId,
        int $clientId,
        int $messageId,
        string $mediaGroupId,
        string $body,
        string $transportChatId = 'chat',
    ): array {
        Queue::fake();
        $organization = Organization::query()->findOrFail($organizationId);
        config()->set('tenancy.default_organization_id', $organizationId);
        app(OrganizationContext::class)->set($organization);
        $turn = app(AcceptCompanionMessage::class)->handle(
            client: Client::query()->findOrFail($clientId),
            channel: 'telegram',
            body: $body,
            idempotencyKey: null,
            originExternalId: 'chat:'.$transportChatId.':'.$messageId,
            transportChatId: $transportChatId,
            locale: 'ru',
            mediaGroupId: $mediaGroupId,
            sourceOrdinal: $messageId,
        );

        return ['turn_id' => (int) $turn->getKey(), 'message_id' => (int) $turn->inbound_message_id];
    }

    /** @return array{conversation_id: int, message_id: int} */
    private function createLegacyConversation(
        Organization $organization,
        Client $client,
        string $channel,
        string $externalKey,
        string $externalMessageId,
        string $body,
    ): array {
        $legacy = new Conversation;
        $legacy->forceFill([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'channel' => $channel,
            'external_key' => $externalKey,
            'conversation_type' => ConversationType::Channel,
            'started_at' => now()->subMinute(),
        ]);
        $legacy->save();

        $message = new ConversationMessage;
        $message->forceFill([
            'organization_id' => $organization->id,
            'conversation_id' => $legacy->id,
            'client_id' => $client->id,
            'channel' => $channel,
            'direction' => ConversationDirection::Inbound,
            'author_type' => ConversationAuthorType::Client,
            'external_id' => $externalMessageId,
            'body' => $body,
            'metadata' => ['source' => 'legacy-concurrency-test'],
            'occurred_at' => now()->subMinute(),
        ]);
        $message->save();

        return ['conversation_id' => (int) $legacy->getKey(), 'message_id' => (int) $message->getKey()];
    }
}

final class PostgresInterleavingCompanionEngine implements AiWorkflowEngine
{
    public function __construct(
        private readonly AiRunResult $result,
        private readonly \Closure $interleave,
    ) {}

    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        ($this->interleave)();

        return $this->result;
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        return $this->result;
    }
}

final class PostgresCompanionChannel implements MessagingChannel
{
    public function name(): string
    {
        return 'portal';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(false, false, false, false);
    }

    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        return NotificationDeliveryResult::delivered('pg-fake');
    }

    public function sendTyping(string $recipientExternalId): bool
    {
        return true;
    }
}
