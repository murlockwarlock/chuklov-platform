<?php

namespace Tests\Feature\ClientCompanion;

use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Services\CompanionMessageBodyReader;
use App\Modules\ClientCompanion\Application\Services\CompanionTurnProcessor;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ClientCompanionProcessingTest extends TestCase
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

    public function test_companion_processing_uses_the_existing_control_plane_and_creates_one_encrypted_response(): void
    {
        $engine = new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'reply',
                'reply' => 'Безопасный ответ из тестового провайдера.',
                'handoff_reason' => '',
                'suggested_safe_actions' => ['feedback_helpful'],
            ],
        ));
        $channel = new RecordingCompanionChannel;
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, $channel);

        $turn = $this->accept('Вопрос клиента');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $turn->refresh();
        self::assertSame(CompanionTurnStatus::Completed, $turn->status);
        self::assertNotNull($turn->outbound_message_id);
        self::assertSame(AiCapability::ClientCompanion, $engine->request?->capability);
        self::assertSame(AiRunOrigin::ClientCompanion, $engine->request?->origin);
        self::assertSame('Вопрос клиента', $engine->request?->inputVariables['current_message']);
        self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->whereNotNull('body')->count());
        self::assertSame(1, CompanionDelivery::query()->where('turn_id', $turn->getKey())->count());
        self::assertSame(['telegram:chat-1'], $channel->typingRecipients);
    }

    public function test_invalid_structured_result_fails_safely_without_exposing_provider_text(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: ['unexpected' => 'raw provider payload'],
        )));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Нужен ответ');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $turn->refresh();
        self::assertSame(CompanionTurnStatus::Failed, $turn->status);
        $outbound = ConversationMessage::query()->findOrFail($turn->outbound_message_id);
        self::assertStringNotContainsString('raw provider payload', app(CompanionMessageBodyReader::class)->read($this->organization->getKey(), $outbound));
        self::assertSame('invalid_output', $turn->failure_code);
    }

    public function test_direct_human_request_escalates_and_pauses_the_same_conversation(): void
    {
        $engine = new RecordingCompanionEngine(new AiRunResult(runId: 0, status: AiRunStatus::Succeeded));
        $channel = new RecordingCompanionChannel;
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, $channel);

        $turn = $this->accept('Мне нужен специалист');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $turn->refresh();
        self::assertSame(CompanionTurnStatus::Escalated, $turn->status);
        self::assertSame(0, $engine->calls);
        self::assertSame(CompanionEscalationReason::HumanRequested, CompanionEscalation::query()->sole()->reason);
        self::assertSame('human_handoff', $turn->conversation()->firstOrFail()->automation_state->value);
        self::assertFalse($turn->typing_active);
    }

    public function test_long_reply_delivers_ordered_chunks_with_actions_only_on_the_final_chunk_and_retry_is_idempotent(): void
    {
        $channel = new RecordingCompanionChannel;
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'reply',
                'reply' => str_repeat('Длинный ответ. ', 2500),
                'handoff_reason' => '',
                'suggested_safe_actions' => ['feedback_helpful'],
            ],
        )));
        $this->app->instance(MessagingChannel::class, $channel);

        $turn = $this->accept('Продолжите подробно');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $deliveries = CompanionDelivery::query()->where('turn_id', $turn->getKey())->orderBy('chunk_index')->get();
        self::assertGreaterThan(1, $deliveries->count());
        foreach ($deliveries as $delivery) {
            (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
                ->handle($channel, app(CompanionMessageBodyReader::class));
        }

        self::assertCount($deliveries->count(), $channel->chunks);
        $buttonIndexes = array_keys(array_filter($channel->chunks, static fn (CompanionOutboundChunk $chunk): bool => $chunk->buttons !== []));
        self::assertSame([count($channel->chunks) - 1], $buttonIndexes);
        foreach ($deliveries as $delivery) {
            (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
                ->handle($channel, app(CompanionMessageBodyReader::class));
        }
        self::assertCount($deliveries->count(), $channel->chunks);
    }

    private function accept(string $body): CompanionTurn
    {
        return app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: $body,
            idempotencyKey: null,
            originExternalId: 'processing-chat:'.CompanionTurn::query()->count().':'.uniqid(),
            transportChatId: 'chat-1',
            locale: 'ru',
        );
    }
}

final class RecordingCompanionEngine implements AiWorkflowEngine
{
    public int $calls = 0;

    public ?AiRunRequest $request = null;

    public function __construct(private readonly AiRunResult $result) {}

    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        $this->calls++;
        $this->request = $request;

        return $this->result;
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        return $this->result;
    }
}

final class RecordingCompanionChannel implements MessagingChannel
{
    /** @var list<string> */
    public array $typingRecipients = [];

    /** @var list<CompanionOutboundChunk> */
    public array $chunks = [];

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
        $this->chunks[] = $chunk;

        return NotificationDeliveryResult::delivered('fake-message-1');
    }

    public function sendTyping(string $recipientExternalId): bool
    {
        $this->typingRecipients[] = 'telegram:'.$recipientExternalId;

        return true;
    }
}
