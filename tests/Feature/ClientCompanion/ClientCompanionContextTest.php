<?php

namespace Tests\Feature\ClientCompanion;

use App\Models\User;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Actions\ResetCompanionContext;
use App\Modules\ClientCompanion\Application\Services\AssembleCompanionContext;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

final class ClientCompanionContextTest extends TestCase
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

    public function test_first_and_recent_exchange_settings_remove_only_the_stale_middle(): void
    {
        $this->setContextSettings(2, 2);
        $conversation = null;

        for ($index = 1; $index <= 6; $index++) {
            $turn = app(AcceptCompanionMessage::class)->handle(
                client: $this->client,
                channel: 'portal',
                body: 'Сообщение '.$index,
                idempotencyKey: 'context-key-'.str_pad((string) $index, 12, '0', STR_PAD_LEFT),
                originExternalId: 'portal:context-'.$index,
            );
            $conversation ??= $turn->conversation()->firstOrFail();
            $this->completeTurn($turn->fresh(), $conversation, 'Ответ '.$index);
        }

        $current = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Текущий вопрос',
            idempotencyKey: 'context-key-current-0001',
            originExternalId: 'portal:context-current',
        );
        $assembled = app(AssembleCompanionContext::class)->handle(
            $this->organization->getKey(),
            $conversation->fresh(),
            $current->fresh(),
        );

        self::assertStringContainsString('Сообщение 1', $assembled['conversation_history']);
        self::assertStringContainsString('Сообщение 2', $assembled['conversation_history']);
        self::assertStringContainsString('Сообщение 5', $assembled['conversation_history']);
        self::assertStringContainsString('Сообщение 6', $assembled['conversation_history']);
        self::assertStringNotContainsString('Сообщение 3', $assembled['conversation_history']);
        self::assertStringNotContainsString('Сообщение 4', $assembled['conversation_history']);
        self::assertStringContainsString('Текущий вопрос', $assembled['current_message']);
        self::assertLessThanOrEqual(24000, mb_strlen($assembled['conversation_history'].' '.$assembled['current_message']));
    }

    public function test_context_reset_starts_a_new_epoch_without_removing_visible_history(): void
    {
        $this->setContextSettings(20, 20);
        $oldTurn = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Старый вопрос',
            idempotencyKey: 'context-key-old-0001',
            originExternalId: 'portal:context-old',
        );
        $conversation = $oldTurn->conversation()->firstOrFail();
        $this->completeTurn($oldTurn->fresh(), $conversation, 'Старый ответ');

        app(ResetCompanionContext::class)->handleForClient($this->client);
        $conversation->refresh();

        $newTurn = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Новый вопрос',
            idempotencyKey: null,
            originExternalId: 'chat-context:2',
            transportChatId: 'chat-context',
        );
        $assembled = app(AssembleCompanionContext::class)->handle(
            $this->organization->getKey(),
            $conversation->fresh(),
            $newTurn->fresh(),
        );

        self::assertSame(2, $conversation->context_epoch);
        self::assertSame(2, $newTurn->context_epoch);
        self::assertStringNotContainsString('Старый вопрос', $assembled['conversation_history']);
        self::assertStringContainsString('Новый вопрос', $assembled['current_message']);
        self::assertSame(3, $conversation->messages()->count());
    }

    public function test_context_settings_cannot_exceed_platform_bounds(): void
    {
        $admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();

        $this->expectException(InvalidArgumentException::class);
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::CompanionContextRecentExchanges,
            21,
        );
    }

    private function setContextSettings(int $first, int $recent): void
    {
        $this->organization->settings()->create([
            'setting_key' => OrganizationSettingKey::CompanionContextFirstExchanges->value,
            'value_type' => 'integer',
            'integer_value' => $first,
        ]);
        $this->organization->settings()->create([
            'setting_key' => OrganizationSettingKey::CompanionContextRecentExchanges->value,
            'value_type' => 'integer',
            'integer_value' => $recent,
        ]);
    }

    private function completeTurn(CompanionTurn $turn, Conversation $conversation, string $body): void
    {
        $outbound = app(RecordCompanionMessage::class)->handle(
            organizationId: $this->organization->getKey(),
            client: $this->client,
            conversation: $conversation,
            channel: 'portal',
            direction: ConversationDirection::Outbound,
            authorType: ConversationAuthorType::Ai,
            body: $body,
            contextEpoch: $turn->context_epoch,
            metadata: ['message_type' => 'companion_reply', 'transport' => 'portal'],
        );
        $turn->update([
            'status' => 'completed',
            'outbound_message_id' => $outbound->getKey(),
            'completed_at' => now(),
            'burst_expires_at' => now()->subSecond(),
        ]);
    }
}
