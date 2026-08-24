<?php

namespace Tests\Feature\ClientCompanion;

use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Infrastructure\Jobs\CompanionTypingHeartbeatJob;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ClientCompanionTypingTest extends TestCase
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

    public function test_processing_turn_refreshes_typing_beyond_one_chat_action_lifetime(): void
    {
        $turn = $this->processingTurn('typing-owner-1');
        $channel = new RecordingTypingChannel;

        (new CompanionTypingHeartbeatJob($this->organization->getKey(), $turn->getKey(), 'typing-owner-1', 0))->handle($channel);
        (new CompanionTypingHeartbeatJob($this->organization->getKey(), $turn->getKey(), 'typing-owner-1', 1))->handle($channel);

        self::assertSame(['telegram-chat', 'telegram-chat'], $channel->typingRecipients);
        self::assertSame(2, $turn->fresh()->typing_heartbeat_sequence);
        Queue::assertPushed(CompanionTypingHeartbeatJob::class, 2);
    }

    public function test_completed_escalated_and_failed_turns_stop_typing_without_new_ownership(): void
    {
        $channel = new RecordingTypingChannel;
        $turn = $this->processingTurn('typing-owner-2');
        $turn->update(['status' => CompanionTurnStatus::Completed, 'typing_active' => false]);
        (new CompanionTypingHeartbeatJob($this->organization->getKey(), $turn->getKey(), 'typing-owner-2', 0))->handle($channel);

        $escalated = $this->processingTurn('typing-owner-3');
        $escalated->update(['status' => CompanionTurnStatus::Escalated, 'typing_active' => false]);
        (new CompanionTypingHeartbeatJob($this->organization->getKey(), $escalated->getKey(), 'typing-owner-3', 0))->handle($channel);

        $failed = $this->processingTurn('typing-owner-4');
        $failed->update(['status' => CompanionTurnStatus::Failed, 'typing_active' => false]);
        (new CompanionTypingHeartbeatJob($this->organization->getKey(), $failed->getKey(), 'typing-owner-4', 0))->handle($channel);

        self::assertSame([], $channel->typingRecipients);
    }

    public function test_stale_worker_cannot_compete_with_the_current_typing_owner(): void
    {
        $turn = $this->processingTurn('new-owner');
        $channel = new RecordingTypingChannel;

        (new CompanionTypingHeartbeatJob($this->organization->getKey(), $turn->getKey(), 'old-owner', 0))->handle($channel);
        (new CompanionTypingHeartbeatJob($this->organization->getKey(), $turn->getKey(), 'new-owner', 0))->handle($channel);

        self::assertSame(['telegram-chat'], $channel->typingRecipients);
    }

    public function test_telegram_failure_is_best_effort_and_does_not_change_ai_turn_state(): void
    {
        $turn = $this->processingTurn('typing-owner-error');
        $channel = new RecordingTypingChannel;
        $channel->throws = true;

        (new CompanionTypingHeartbeatJob($this->organization->getKey(), $turn->getKey(), 'typing-owner-error', 0))->handle($channel);

        self::assertSame(CompanionTurnStatus::Processing, $turn->fresh()->status);
        self::assertTrue($turn->fresh()->typing_active);
        self::assertSame(1, $turn->fresh()->typing_heartbeat_sequence);
        self::assertSame(1, $channel->calls);
    }

    private function processingTurn(string $owner): CompanionTurn
    {
        $turn = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Долгий вопрос',
            idempotencyKey: null,
            originExternalId: 'typing-chat:'.$owner,
            transportChatId: 'telegram-chat',
        );
        $turn->update([
            'status' => CompanionTurnStatus::Processing,
            'typing_active' => true,
            'typing_owner_token' => $owner,
            'typing_heartbeat_sequence' => 0,
            'typing_chat_id' => 'telegram-chat',
            'processing_lease_expires_at' => now()->addMinute(),
        ]);

        return $turn->fresh();
    }
}

final class RecordingTypingChannel implements MessagingChannel
{
    /** @var list<string> */
    public array $typingRecipients = [];

    public int $calls = 0;

    public bool $throws = false;

    public function name(): string
    {
        return 'telegram';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(true, true, true, true);
    }

    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        return NotificationDeliveryResult::delivered();
    }

    public function sendTyping(string $recipientExternalId): bool
    {
        $this->calls++;
        if ($this->throws) {
            throw new \RuntimeException('Telegram chat action unavailable.');
        }
        $this->typingRecipients[] = $recipientExternalId;

        return true;
    }
}
