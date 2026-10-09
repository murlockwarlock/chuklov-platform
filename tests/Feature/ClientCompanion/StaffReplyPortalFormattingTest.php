<?php

namespace Tests\Feature\ClientCompanion;

use App\Models\User;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Actions\ReplyToCompanion;
use App\Modules\ClientCompanion\Application\Actions\TakeOverCompanionConversation;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StaffReplyPortalFormattingTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_staff_reply_projects_sanitized_html_for_the_existing_portal_renderer(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        app(AcceptCompanionMessage::class)->handle($client, 'portal', 'Вопрос клиента', 'format-proof-0001', 'portal:format-proof-0001');
        app(TakeOverCompanionConversation::class)->handle($actor, $client);
        app(ReplyToCompanion::class)->handle($actor, $client, '<p>Ответ <strong>специалиста</strong></p><p><a href="https://example.test/instructions">Инструкция</a></p><script>alert(1)</script>');
        app(RecordCompanionMessage::class)->handle(
            organizationId: $organization->getKey(),
            client: $client,
            conversation: Conversation::query()->sole(),
            channel: 'portal',
            direction: ConversationDirection::Outbound,
            authorType: ConversationAuthorType::Ai,
            body: '<img src=x onerror="alert(1)"><p>Untrusted provider output</p>',
        );
        $reply = ConversationMessage::query()->where('author_type', ConversationAuthorType::Staff)->sole();

        self::assertNull($reply->body);
        self::assertNotNull($reply->encrypted_body);
        $response = $this->withSession(['client_portal.client_id' => $client->getKey()])->get('/portal/companion')->assertOk();
        $message = collect($response->inertiaProps('companion.messages'))->firstWhere('id', $reply->getKey());

        self::assertIsArray($message);
        self::assertArrayHasKey('contentHtml', $message);
        self::assertStringContainsString('<strong>специалиста</strong>', $message['contentHtml']);
        self::assertStringContainsString('href="https://example.test/instructions"', $message['contentHtml']);
        self::assertStringNotContainsString('<script', $message['contentHtml']);
        self::assertStringNotContainsString('alert(1)', $message['contentHtml']);
        self::assertNull(collect($response->inertiaProps('companion.messages'))->firstWhere('role', 'client')['contentHtml']);
        self::assertNull(collect($response->inertiaProps('companion.messages'))->firstWhere('role', 'ai')['contentHtml']);
    }
}
