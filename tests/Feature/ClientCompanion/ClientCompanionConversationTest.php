<?php

namespace Tests\Feature\ClientCompanion;

use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Services\CompanionMessageBodyReader;
use App\Modules\ClientCompanion\Application\Services\ReadCompanionConversation;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Infrastructure\Jobs\ProcessCompanionTurn;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ClientCompanionConversationTest extends TestCase
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
        Queue::fake();
    }

    public function test_portal_and_telegram_share_one_encrypted_logical_conversation_and_one_burst_turn(): void
    {
        $accept = app(AcceptCompanionMessage::class);
        $portalTurn = $accept->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Привет',
            idempotencyKey: 'portal-key-00000001',
            originExternalId: 'portal:portal-key-00000001',
            locale: 'ru',
        );
        $telegramTurn = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'после массажа шея тянет',
            idempotencyKey: null,
            originExternalId: 'chat-1:100',
            transportChatId: 'chat-1',
            locale: 'ru',
        );

        self::assertSame($portalTurn->conversation_id, $telegramTurn->conversation_id);
        self::assertSame(1, Conversation::query()->where('conversation_type', 'client_companion')->count());
        self::assertSame(1, CompanionTurn::query()->count());
        self::assertSame(2, ConversationMessage::query()->count());
        self::assertSame(0, ConversationMessage::query()->whereNotNull('body')->count());
        Queue::assertPushed(ProcessCompanionTurn::class, 1);

        foreach (ConversationMessage::query()->get() as $message) {
            self::assertNotNull($message->encrypted_body);
        }

        $messages = ConversationMessage::query()->orderBy('id')->get();
        self::assertSame('Привет', app(CompanionMessageBodyReader::class)->read($this->organization->id, $messages[0]));
        self::assertSame('после массажа шея тянет', app(CompanionMessageBodyReader::class)->read($this->organization->id, $messages[1]));
    }

    public function test_portal_idempotency_is_retry_safe_and_payload_reuse_fails_closed(): void
    {
        $accept = app(AcceptCompanionMessage::class);
        $first = $accept->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Один запрос',
            idempotencyKey: 'portal-key-00000002',
            originExternalId: 'portal:portal-key-00000002',
        );
        $duplicate = $accept->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Один запрос',
            idempotencyKey: 'portal-key-00000002',
            originExternalId: 'portal:portal-key-00000002',
        );

        self::assertSame($first->getKey(), $duplicate->getKey());
        self::assertSame(1, ConversationMessage::query()->count());

        $this->expectException(AuthorizationException::class);
        $accept->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Другой запрос',
            idempotencyKey: 'portal-key-00000002',
            originExternalId: 'portal:portal-key-00000002',
        );
    }

    public function test_cross_client_and_cross_organization_access_fails_closed(): void
    {
        $otherClient = Client::factory()->forOrganization($this->organization)->create();
        $accept = app(AcceptCompanionMessage::class);
        $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Только мой разговор',
            idempotencyKey: null,
            originExternalId: 'chat-1:200',
            transportChatId: 'chat-1',
        );

        try {
            $accept->handle(
                client: $otherClient,
                channel: 'telegram',
                body: 'Чужой разговор',
                idempotencyKey: null,
                originExternalId: 'chat-1:200',
                transportChatId: 'chat-1',
            );
            self::fail('Expected a Telegram identity collision to fail closed.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $otherOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($otherOrganization)->create();
        $this->expectException(AuthorizationException::class);
        $accept->handle(
            client: $foreignClient,
            channel: 'portal',
            body: 'Чужая организация',
            idempotencyKey: 'portal-key-00000003',
            originExternalId: 'portal:portal-key-00000003',
        );
    }

    public function test_history_reader_is_bounded_and_cannot_read_another_client(): void
    {
        $accept = app(AcceptCompanionMessage::class);
        for ($index = 1; $index <= 4; $index++) {
            $accept->handle(
                client: $this->client,
                channel: 'portal',
                body: 'Сообщение '.$index,
                idempotencyKey: 'portal-key-00000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                originExternalId: 'portal:history-'.$index,
            );
        }

        config()->set('ai.companion.history_page_size', 2);
        $history = app(ReadCompanionConversation::class)->forClient($this->client);
        self::assertLessThanOrEqual(2, count($history['messages']));
        self::assertTrue($history['hasOlder']);

        $foreign = Client::factory()->forOrganization($this->organization)->create();
        $foreignHistory = app(ReadCompanionConversation::class)->forClient($foreign);
        self::assertArrayNotHasKey('conversation', $foreignHistory);
        self::assertSame([], $foreignHistory['messages']);
    }

    public function test_ordinary_client_history_does_not_expose_internal_conversation_id(): void
    {
        app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Безопасный вопрос',
            idempotencyKey: 'portal-safe-id-0001',
            originExternalId: 'portal:safe-id-0001',
        );

        $history = app(ReadCompanionConversation::class)->forClient($this->client);

        self::assertArrayNotHasKey('conversation', $history);
        self::assertArrayNotHasKey('aiRunId', $history);
        self::assertArrayNotHasKey('providerId', $history);
    }

    public function test_burst_limits_split_later_messages_without_dropping_them(): void
    {
        config()->set('ai.companion.maximum_burst_messages', 2);
        $accept = app(AcceptCompanionMessage::class);

        $first = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Привет',
            idempotencyKey: null,
            originExternalId: 'burst-limit:1',
            transportChatId: 'burst-limit',
        );
        $second = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'После массажа',
            idempotencyKey: null,
            originExternalId: 'burst-limit:2',
            transportChatId: 'burst-limit',
        );
        $third = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Шея тянет',
            idempotencyKey: null,
            originExternalId: 'burst-limit:3',
            transportChatId: 'burst-limit',
        );

        self::assertSame($first->getKey(), $second->getKey());
        self::assertNotSame($first->getKey(), $third->getKey());
        self::assertSame(3, ConversationMessage::query()->count());

        Carbon::setTestNow(now()->addSeconds(2));
        $outsideWindow = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Отдельный вопрос',
            idempotencyKey: null,
            originExternalId: 'burst-limit:4',
            transportChatId: 'burst-limit',
        );
        Carbon::setTestNow();

        self::assertNotSame($third->getKey(), $outsideWindow->getKey());
        self::assertSame(4, ConversationMessage::query()->count());
    }
}
