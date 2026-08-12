<?php

namespace Tests\Feature;

use App\Modules\Conversations\Application\RecordConversationMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneTwoConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_are_normalized_scoped_and_idempotent(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        $message = app(RecordConversationMessage::class)->handle(
            client: $client,
            channel: 'Telegram',
            conversationKey: 'chat-100',
            direction: ConversationDirection::Inbound,
            authorType: ConversationAuthorType::Client,
            body: 'Hello',
            externalMessageId: 'message-100',
            metadata: [
                'chat_type' => 'private',
                'raw_update' => 'must-not-persist',
            ],
        );
        $duplicate = app(RecordConversationMessage::class)->handle(
            client: $client,
            channel: 'telegram',
            conversationKey: 'chat-100',
            direction: ConversationDirection::Inbound,
            authorType: ConversationAuthorType::Client,
            body: 'Changed body must not replace the original',
            externalMessageId: 'message-100',
        );

        self::assertSame($message->id, $duplicate->id);
        self::assertSame(ConversationDirection::Inbound, $message->direction);
        self::assertSame(ConversationAuthorType::Client, $message->author_type);
        self::assertSame(['chat_type' => 'private'], $message->metadata);
        self::assertSame(1, Conversation::query()->count());
        self::assertSame(1, ConversationMessage::query()->count());
        self::assertSame('Hello', $message->body);
    }

    public function test_conversation_message_cannot_cross_organization_or_client_boundaries(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        app(OrganizationContext::class)->set($organization);

        $this->expectException(AuthorizationException::class);

        app(RecordConversationMessage::class)->handle(
            client: $otherClient,
            channel: 'telegram',
            conversationKey: 'chat-200',
            direction: ConversationDirection::Inbound,
            authorType: ConversationAuthorType::Client,
        );

    }
}
