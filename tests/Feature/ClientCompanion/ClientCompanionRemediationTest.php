<?php

namespace Tests\Feature\ClientCompanion;

use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Services\CompanionMessageBodyReader;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
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
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientCompanionRemediationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->client = Client::factory()->forOrganization($this->organization)->create();
        app(OrganizationContext::class)->set($this->organization);
    }

    public function test_legacy_telegram_history_is_adopted_into_the_unified_companion(): void
    {
        [$legacy, $message] = $this->legacyConversation($this->organization, $this->client, 'telegram', 'chat:legacy-telegram', 'Старое сообщение Telegram');

        app(AdoptLegacyCompanionConversations::class)->handle();

        $companion = Conversation::query()->where('client_id', $this->client->getKey())->where('conversation_type', ConversationType::ClientCompanion)->sole();
        self::assertSame($companion->getKey(), $message->refresh()->conversation_id);
        self::assertNull($message->body);
        self::assertNotNull($message->encrypted_body);
        self::assertSame($companion->getKey(), ConversationBinding::query()->where('channel', 'telegram')->where('external_key', 'chat:legacy-telegram')->sole()->conversation_id);
        self::assertSame('Старое сообщение Telegram', app(CompanionMessageBodyReader::class)->read($this->organization->getKey(), $message->refresh()));
        self::assertSame(ConversationType::ClientCompanion, $legacy->refresh()->conversation_type);
        self::assertSame($legacy->getKey(), $companion->getKey());
    }

    public function test_legacy_portal_history_is_adopted_into_the_unified_companion(): void
    {
        [$legacy, $message] = $this->legacyConversation($this->organization, $this->client, 'portal', 'portal:legacy-portal', 'Старое сообщение Portal');

        app(AdoptLegacyCompanionConversations::class)->handle();

        $companion = Conversation::query()->where('client_id', $this->client->getKey())->where('conversation_type', ConversationType::ClientCompanion)->sole();
        self::assertSame($companion->getKey(), $message->refresh()->conversation_id);
        self::assertSame('Старое сообщение Portal', app(CompanionMessageBodyReader::class)->read($this->organization->getKey(), $message->refresh()));
        self::assertSame('portal', ConversationBinding::query()->where('conversation_id', $companion->getKey())->sole()->channel);
        self::assertSame(ConversationType::ClientCompanion, $legacy->refresh()->conversation_type);
        self::assertSame($legacy->getKey(), $companion->getKey());
    }

    public function test_legacy_telegram_and_portal_histories_share_one_ordered_companion(): void
    {
        $base = CarbonImmutable::parse('2026-08-23 10:00:00');
        [$telegram, $telegramMessage] = $this->legacyConversation($this->organization, $this->client, 'telegram', 'chat:chronology', 'Telegram раньше', $base);
        [, $portalMessage] = $this->legacyConversation($this->organization, $this->client, 'portal', 'portal:chronology', 'Portal позже', $base->addMinute());

        app(AdoptLegacyCompanionConversations::class)->handle();

        $companion = Conversation::query()->where('client_id', $this->client->getKey())->where('conversation_type', ConversationType::ClientCompanion)->sole();
        self::assertSame($companion->getKey(), $telegramMessage->refresh()->conversation_id);
        self::assertSame($companion->getKey(), $portalMessage->refresh()->conversation_id);
        self::assertSame(['Telegram раньше', 'Portal позже'], ConversationMessage::query()
            ->where('conversation_id', $companion->getKey())
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (ConversationMessage $message): string => app(CompanionMessageBodyReader::class)->read($this->organization->getKey(), $message))
            ->all());
        self::assertCount(2, ConversationBinding::query()->where('conversation_id', $companion->getKey())->get());
        self::assertSame($telegram->getKey(), $companion->getKey());
    }

    public function test_multiple_historical_telegram_keys_bind_to_one_client_companion(): void
    {
        [, $firstMessage] = $this->legacyConversation($this->organization, $this->client, 'telegram', 'chat:historical-a', 'Старый чат A');
        [, $secondMessage] = $this->legacyConversation($this->organization, $this->client, 'telegram', 'chat:historical-b', 'Старый чат B');

        app(AdoptLegacyCompanionConversations::class)->handle();

        $companion = Conversation::query()->where('client_id', $this->client->getKey())->where('conversation_type', ConversationType::ClientCompanion)->sole();
        self::assertSame($companion->getKey(), $firstMessage->refresh()->conversation_id);
        self::assertSame($companion->getKey(), $secondMessage->refresh()->conversation_id);
        self::assertCount(2, ConversationBinding::query()->where('conversation_id', $companion->getKey())->where('channel', 'telegram')->get());
        self::assertSame(2, ConversationMessage::query()->where('conversation_id', $companion->getKey())->count());
    }

    public function test_similar_external_keys_for_two_clients_never_merge(): void
    {
        $otherClient = Client::factory()->forOrganization($this->organization)->create();
        $this->legacyConversation($this->organization, $this->client, 'telegram', 'same-client-key-a', 'Первый клиент');
        $this->legacyConversation($this->organization, $otherClient, 'telegram', 'same-client-key-b', 'Второй клиент');

        app(AdoptLegacyCompanionConversations::class)->handle();

        self::assertSame(2, Conversation::query()->where('conversation_type', ConversationType::ClientCompanion)->count());
        self::assertCount(1, ConversationBinding::query()->where('client_id', $this->client->getKey())->get());
        self::assertCount(1, ConversationBinding::query()->where('client_id', $otherClient->getKey())->get());
    }

    public function test_same_external_key_in_two_organizations_never_cross_merges(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $this->legacyConversation($this->organization, $this->client, 'telegram', 'shared-chat-key', 'Организация A');
        $this->legacyConversation($otherOrganization, $otherClient, 'telegram', 'shared-chat-key', 'Организация B');

        app(AdoptLegacyCompanionConversations::class)->handle();

        self::assertSame(1, Conversation::query()->where('organization_id', $this->organization->getKey())->where('conversation_type', ConversationType::ClientCompanion)->count());
        self::assertSame(1, Conversation::query()->where('organization_id', $otherOrganization->getKey())->where('conversation_type', ConversationType::ClientCompanion)->count());
        self::assertSame(2, ConversationBinding::query()->where('channel', 'telegram')->where('external_key', 'shared-chat-key')->count());
    }

    public function test_ambiguous_legacy_external_key_is_preserved_without_a_guessed_merge(): void
    {
        [$legacy, $message] = $this->legacyConversation($this->organization, $this->client, 'telegram', 'Имя клиента', 'Не объединять');

        $stats = app(AdoptLegacyCompanionConversations::class)->handle();

        self::assertSame(1, $stats['ambiguous']);
        self::assertSame(ConversationType::Channel, $legacy->refresh()->conversation_type);
        self::assertSame($legacy->getKey(), $message->refresh()->conversation_id);
        self::assertSame('Не объединять', $message->refresh()->body);
        self::assertSame(0, ConversationBinding::query()->count());
        self::assertSame(0, Conversation::query()->where('conversation_type', ConversationType::ClientCompanion)->count());
    }

    public function test_adoption_is_idempotent_and_preserves_message_ids_on_rerun(): void
    {
        $this->legacyConversation($this->organization, $this->client, 'portal', 'portal:rerun', 'Один раз');
        $adopter = app(AdoptLegacyCompanionConversations::class);
        $adopter->handle();
        $messageIds = ConversationMessage::query()->pluck('id')->all();
        $conversationId = Conversation::query()->where('conversation_type', ConversationType::ClientCompanion)->value('id');

        $adopter->handle();

        self::assertSame($messageIds, ConversationMessage::query()->pluck('id')->all());
        self::assertSame($conversationId, Conversation::query()->where('conversation_type', ConversationType::ClientCompanion)->value('id'));
        self::assertSame(1, ConversationBinding::query()->count());
        self::assertSame(1, ConversationMessage::query()->where('conversation_id', $conversationId)->count());
    }

    public function test_adopted_provider_message_identity_is_not_recorded_a_second_time(): void
    {
        [, $message] = $this->legacyConversation($this->organization, $this->client, 'telegram', 'chat:dedup', 'Повторное обновление');
        app(AdoptLegacyCompanionConversations::class)->handle();
        $before = ConversationMessage::query()->count();

        $duplicate = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Повторное обновление',
            idempotencyKey: null,
            originExternalId: $message->external_id,
            transportChatId: 'chat:dedup',
            locale: 'ru',
        );

        self::assertSame($before, ConversationMessage::query()->count());
        self::assertSame(CompanionTurn::query()->where('input_failure_code', 'legacy_adopted_duplicate')->sole()->getKey(), $duplicate->getKey());
    }

    public function test_adopted_provider_message_identity_cannot_be_reused_for_different_content(): void
    {
        [, $message] = $this->legacyConversation($this->organization, $this->client, 'telegram', 'chat:dedup-content', 'Исходный текст');
        app(AdoptLegacyCompanionConversations::class)->handle();

        $this->expectException(AuthorizationException::class);
        app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Подменённый текст',
            idempotencyKey: null,
            originExternalId: $message->external_id,
            transportChatId: 'chat:dedup-content',
            locale: 'ru',
        );
    }

    /** @return array{0: Conversation, 1: ConversationMessage} */
    private function legacyConversation(
        Organization $organization,
        Client $client,
        string $channel,
        ?string $externalKey,
        string $body,
        ?CarbonImmutable $occurredAt = null,
    ): array {
        $conversation = new Conversation;
        $conversation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'channel' => $channel,
            'external_key' => $externalKey,
            'conversation_type' => ConversationType::Channel,
            'started_at' => $occurredAt ?? now(),
            'last_message_at' => $occurredAt ?? now(),
        ]);
        $conversation->save();

        $message = new ConversationMessage;
        $message->forceFill([
            'organization_id' => $organization->getKey(),
            'conversation_id' => $conversation->getKey(),
            'client_id' => $client->getKey(),
            'channel' => $channel,
            'direction' => ConversationDirection::Inbound,
            'author_type' => ConversationAuthorType::Client,
            'external_id' => $channel.':message:'.$conversation->getKey(),
            'body' => $body,
            'metadata' => ['legacy' => true],
            'occurred_at' => $occurredAt ?? now(),
        ]);
        $message->save();

        return [$conversation->refresh(), $message->refresh()];
    }
}
