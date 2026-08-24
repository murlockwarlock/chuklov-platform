<?php

namespace Tests\Feature\ClientCompanion;

use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Services\CompanionMessageBodyReader;
use App\Modules\ClientCompanion\Application\Services\CompanionTurnProcessor;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnMessage;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
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
        Queue::assertPushed(DeliverCompanionMessage::class, 1);
        foreach ($deliveries as $delivery) {
            (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
                ->handle($channel, app(CompanionMessageBodyReader::class));
            self::assertSame(CompanionDeliveryStatus::Delivered, $delivery->fresh()->status);
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

    public function test_stale_completion_cannot_publish_after_a_new_worker_reclaims_the_turn(): void
    {
        $turn = $this->accept('Поздний ответ');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Ответ A', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            function () use ($turn): void {
                CompanionTurn::query()->whereKey($turn->getKey())->update([
                    'processing_lease_token' => 'worker-b-token',
                    'processing_lease_expires_at' => now()->addMinutes(5),
                ]);
            },
        ));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Processing, $turn->fresh()->status);
        self::assertSame('worker-b-token', $turn->fresh()->processing_lease_token);
        self::assertNull($turn->fresh()->outbound_message_id);
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertSame(0, CompanionDelivery::query()->count());
    }

    public function test_stale_handoff_cannot_escalate_after_lease_replacement(): void
    {
        $turn = $this->accept('Передача специалисту');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'handoff_required', 'reply' => 'Ответ', 'handoff_reason' => 'human', 'suggested_safe_actions' => []],
            ),
            fn (): mixed => CompanionTurn::query()->whereKey($turn->getKey())->update([
                'processing_lease_token' => 'worker-b-token',
                'processing_lease_expires_at' => now()->addMinutes(5),
            ]),
        ));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Processing, $turn->fresh()->status);
        self::assertSame(0, CompanionEscalation::query()->count());
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->count());
    }

    public function test_stale_failure_cannot_overwrite_the_reclaiming_worker(): void
    {
        $turn = $this->accept('Ошибка провайдера');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new ThrowingInterleavingEngine(function () use ($turn): void {
            CompanionTurn::query()->whereKey($turn->getKey())->update([
                'processing_lease_token' => 'worker-b-token',
                'processing_lease_expires_at' => now()->addMinutes(5),
            ]);
        }));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Processing, $turn->fresh()->status);
        self::assertNull($turn->fresh()->failure_code);
        self::assertNull($turn->fresh()->outbound_message_id);
    }

    public function test_reclaimed_worker_completes_exactly_one_response(): void
    {
        $turn = $this->accept('Повторная обработка');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $engine = new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Один ответ', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            function () use ($turn): void {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    CompanionTurn::query()->whereKey($turn->getKey())->update([
                        'processing_lease_token' => 'worker-b-token',
                        'processing_lease_expires_at' => now()->subSecond(),
                    ]);
                }
            },
        );
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $processor = app(CompanionTurnProcessor::class);
        $processor->handle($this->organization->getKey(), $turn->getKey());

        $processor->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Completed, $turn->fresh()->status);
        self::assertSame(2, $engine->calls);
        self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertSame(1, CompanionDelivery::query()->where('turn_id', $turn->getKey())->count());
    }

    public function test_context_reset_during_processing_cancels_old_epoch_without_output(): void
    {
        $turn = $this->accept('Сброс контекста');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Старый ответ', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            function () use ($turn): void {
                Conversation::query()->whereKey($turn->conversation_id)->update(['context_epoch' => 2]);
            },
        ));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Cancelled, $turn->fresh()->status);
        self::assertNull($turn->fresh()->outbound_message_id);
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->count());
    }

    public function test_valid_ai_execution_beyond_the_old_180_second_lease_boundary_keeps_one_owner(): void
    {
        $startedAt = Carbon::create(2026, 8, 24, 12, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $engine = new RecordingCompanionEngine(
                new AiRunResult(
                    runId: 0,
                    status: AiRunStatus::Succeeded,
                    outputPayload: ['decision' => 'reply', 'reply' => 'Долгий ответ', 'handoff_reason' => '', 'suggested_safe_actions' => []],
                ),
                function () use ($startedAt): void {
                    Carbon::setTestNow($startedAt->copy()->addSeconds(181));
                },
            );
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $turn = $this->accept('Длинный запуск');
            $turn->update(['burst_expires_at' => $startedAt->copy()->subSecond()]);

            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

            self::assertSame(CompanionTurnStatus::Completed, $turn->fresh()->status);
            self::assertSame(1, $engine->calls);
            self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
            self::assertSame(
                $startedAt->copy()->addSeconds(AiRuntimeLimits::wholeRunSeconds())->getPreciseTimestamp(6),
                $engine->request?->executionDeadlineAt?->getPreciseTimestamp(6),
            );
            self::assertGreaterThan(180, AiRuntimeLimits::companionProcessingLeaseSeconds());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_processing_recovery_after_the_authoritative_deadline_fails_once_without_ai_replay(): void
    {
        $now = Carbon::create(2026, 8, 24, 13, 0, 0, 'UTC');
        Carbon::setTestNow($now);
        try {
            $engine = new RecordingCompanionEngine(new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Не должно выполниться', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ));
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $turn = $this->accept('Просроченный запуск');
            $turn->update([
                'status' => CompanionTurnStatus::Processing,
                'processing_lease_token' => 'dead-worker',
                'processing_lease_expires_at' => $now->copy()->subSecond(),
                'execution_deadline_at' => $now->copy()->subSecond(),
                'burst_expires_at' => null,
            ]);

            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

            $turn->refresh();
            self::assertSame(CompanionTurnStatus::Failed, $turn->status);
            self::assertSame('execution_deadline_exceeded', $turn->failure_code);
            self::assertSame(0, $engine->calls);
            self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
            self::assertNull($turn->processing_lease_token);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failover_window_beyond_the_old_lease_boundary_keeps_terminal_fencing(): void
    {
        $startedAt = Carbon::create(2026, 8, 24, 14, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $engine = new RecordingCompanionEngine(
                new AiRunResult(
                    runId: 0,
                    status: AiRunStatus::Succeeded,
                    outputPayload: ['decision' => 'reply', 'reply' => 'Ответ после failover', 'handoff_reason' => '', 'suggested_safe_actions' => []],
                ),
                function () use ($startedAt): void {
                    Carbon::setTestNow($startedAt->copy()->addSeconds(181));
                },
            );
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $turn = $this->accept('Проверка failover');
            $turn->update(['burst_expires_at' => $startedAt->copy()->subSecond()]);

            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

            self::assertSame(CompanionTurnStatus::Completed, $turn->fresh()->status);
            self::assertSame(1, $engine->calls);
            self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_human_handoff_during_processing_prevents_late_ai_output(): void
    {
        $turn = $this->accept('Пауза специалистом');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Не публиковать', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            function () use ($turn): void {
                Conversation::query()->whereKey($turn->conversation_id)->update(['automation_state' => ConversationAutomationState::HumanHandoff]);
            },
        ));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Paused, $turn->fresh()->status);
        self::assertNull($turn->fresh()->outbound_message_id);
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->count());
    }

    public function test_explicit_retryable_delivery_failure_is_bounded_and_persisted(): void
    {
        $delivery = $this->createDeliveries('Повторить доставку')->sole();
        $channel = new RetryableCompanionChannel;

        (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        $delivery->refresh();
        self::assertSame(CompanionDeliveryStatus::Failed, $delivery->status);
        self::assertSame('provider_rejected_before_acceptance', $delivery->last_error_code);
        self::assertNotNull($delivery->next_attempt_at);
        self::assertCount(1, $channel->chunks);
    }

    public function test_external_side_effect_followed_by_exception_becomes_uncertain_without_replay(): void
    {
        $delivery = $this->createDeliveries('Не дублировать после сбоя')->sole();
        $channel = new CrashAfterExternalSideEffectCompanionChannel;
        $job = new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey());

        $job->handle($channel, app(CompanionMessageBodyReader::class));
        $job->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Uncertain, $delivery->fresh()->status);
        self::assertSame('delivery_send_exception_unknown', $delivery->fresh()->last_error_code);
        self::assertCount(1, $channel->chunks);
    }

    public function test_expired_delivery_lease_becomes_uncertain_without_replaying_the_provider_call(): void
    {
        $delivery = $this->createDeliveries('Не повторять неизвестную отправку')->sole();
        $delivery->update([
            'status' => CompanionDeliveryStatus::Processing,
            'processing_lease_token' => 'dead-worker',
            'processing_lease_expires_at' => now()->subSecond(),
        ]);
        $channel = new RecordingCompanionChannel;
        $channel->chunks[] = new CompanionOutboundChunk('chat-1', 'side effect already happened', 0, 1, 'ru');

        (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Uncertain, $delivery->fresh()->status);
        self::assertSame('delivery_lease_expired_unknown', $delivery->fresh()->last_error_code);
        self::assertCount(1, $channel->chunks);
    }

    public function test_stale_delivery_worker_cannot_finalize_over_a_new_lease(): void
    {
        $delivery = $this->createDeliveries('Фехтование доставки')->sole();
        $channel = new LeaseReplacingCompanionChannel($delivery->getKey());

        (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Processing, $delivery->fresh()->status);
        self::assertSame('new-delivery-worker', $delivery->fresh()->processing_lease_token);
        self::assertCount(1, $channel->chunks);
    }

    public function test_uncertain_first_chunk_blocks_later_chunks_without_replaying_any_chunk(): void
    {
        $deliveries = $this->createDeliveries(str_repeat('Длинный ответ. ', 2500));
        self::assertGreaterThan(1, $deliveries->count());
        $first = $deliveries->firstOrFail();
        $first->update([
            'status' => CompanionDeliveryStatus::Processing,
            'processing_lease_token' => 'dead-worker',
            'processing_lease_expires_at' => now()->subSecond(),
        ]);
        $channel = new RecordingCompanionChannel;

        (new DeliverCompanionMessage($this->organization->getKey(), $first->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));
        $second = $deliveries->get(1)->fresh();
        (new DeliverCompanionMessage($this->organization->getKey(), $second->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Uncertain, $first->fresh()->status);
        self::assertSame(CompanionDeliveryStatus::Failed, $second->fresh()->status);
        self::assertSame('blocked_by_previous_delivery', $second->fresh()->last_error_code);
        self::assertCount(0, $channel->chunks);
    }

    /** @return Collection<int, CompanionDelivery> */
    private function createDeliveries(string $reply): Collection
    {
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: ['decision' => 'reply', 'reply' => $reply, 'handoff_reason' => '', 'suggested_safe_actions' => []],
        )));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $turn = $this->accept('Доставить ответ');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        return CompanionDelivery::query()->where('turn_id', $turn->getKey())->orderBy('chunk_index')->get();
    }

    public function test_media_group_stays_assembling_when_worker_runs_before_the_quiet_window(): void
    {
        $engine = new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: ['decision' => 'reply', 'reply' => 'Фото принято', 'handoff_reason' => '', 'suggested_safe_actions' => []],
        ));
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $first = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'альбом 1',
            idempotencyKey: null,
            originExternalId: 'album-race:1',
            transportChatId: 'album-chat',
            locale: 'ru',
            mediaGroupId: 'album-race',
            sourceOrdinal: 20,
        );

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());
        $second = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'альбом 2',
            idempotencyKey: null,
            originExternalId: 'album-race:2',
            transportChatId: 'album-chat',
            locale: 'ru',
            mediaGroupId: 'album-race',
            sourceOrdinal: 21,
        );

        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame(CompanionTurnStatus::Assembling, $first->fresh()->status);
        self::assertSame(0, $engine->calls);
        self::assertSame(2, CompanionTurnMessage::query()->where('turn_id', $first->getKey())->count());

        $first->refresh()->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());

        self::assertSame(CompanionTurnStatus::Completed, $first->fresh()->status);
        self::assertSame(1, $engine->calls);
        self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
    }

    public function test_album_items_separated_by_more_than_the_old_window_stay_one_turn_until_the_new_quiet_deadline(): void
    {
        $startedAt = Carbon::create(2026, 8, 24, 15, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $engine = new RecordingCompanionEngine(new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Весь альбом', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ));
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $first = $this->acceptAlbumItem('album-spacing:1', 'album-spacing', 'первое', 1);

            Carbon::setTestNow($startedAt->copy()->addSeconds(2));
            $second = $this->acceptAlbumItem('album-spacing:2', 'album-spacing', 'второе', 2);

            self::assertSame($first->getKey(), $second->getKey());
            self::assertSame(CompanionTurnStatus::Assembling, $first->fresh()->status);
            self::assertSame(2, CompanionTurnMessage::query()->where('turn_id', $first->getKey())->count());
            self::assertSame(0, $engine->calls);

            Carbon::setTestNow($startedAt->copy()->addSeconds(5));
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());
            self::assertSame(CompanionTurnStatus::Assembling, $first->fresh()->status);
            self::assertSame(0, $engine->calls);

            Carbon::setTestNow($startedAt->copy()->addSeconds(8));
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());

            self::assertSame(CompanionTurnStatus::Completed, $first->fresh()->status);
            self::assertSame(1, $engine->calls);
            self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_album_item_just_before_the_quiet_boundary_refreshes_the_authoritative_deadline(): void
    {
        $startedAt = Carbon::create(2026, 8, 24, 16, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $engine = new RecordingCompanionEngine(new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Граница альбома', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ));
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $first = $this->acceptAlbumItem('album-boundary:1', 'album-boundary', 'первое', 1);

            Carbon::setTestNow($startedAt->copy()->addSeconds(4));
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());
            $second = $this->acceptAlbumItem('album-boundary:2', 'album-boundary', 'второе', 2);

            self::assertSame($first->getKey(), $second->getKey());
            self::assertSame(2, CompanionTurnMessage::query()->where('turn_id', $first->getKey())->count());
            self::assertSame(0, $engine->calls);

            Carbon::setTestNow($startedAt->copy()->addSeconds(10));
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());
            self::assertSame(CompanionTurnStatus::Completed, $first->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_album_item_during_ai_execution_cancels_the_old_run_before_it_can_publish(): void
    {
        $engine = new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Неполный ответ', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            function (): void {
                $turn = CompanionTurn::query()->where('media_group_id', 'album-during-run')->sole();
                app(AcceptCompanionMessage::class)->handle(
                    client: $this->client,
                    channel: 'telegram',
                    body: 'позднее фото',
                    idempotencyKey: null,
                    originExternalId: 'album-during-run:2',
                    transportChatId: 'run-chat',
                    locale: 'ru',
                    mediaGroupId: 'album-during-run',
                    sourceOrdinal: 2,
                );
                self::assertSame(CompanionTurnStatus::Cancelled, $turn->fresh()->status);
            },
        );
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $turn = $this->acceptAlbumItem('album-during-run:1', 'album-during-run', 'первое фото', 1);
        $turn->update(['burst_expires_at' => now()->subSecond()]);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Cancelled, $turn->fresh()->status);
        self::assertNotNull($turn->fresh()->album_recovery_message_id);
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertSame(1, $engine->calls);
    }

    public function test_album_hard_assembly_deadline_fails_closed_without_silent_partial_analysis(): void
    {
        config()->set('ai.companion.album_max_assembly_seconds', 5);
        $startedAt = Carbon::create(2026, 8, 24, 17, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $engine = new RecordingCompanionEngine(new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Не запускать', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ));
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $turn = $this->acceptAlbumItem('album-hard-limit:1', 'album-hard-limit', 'первое', 1);

            Carbon::setTestNow($startedAt->copy()->addSeconds(6));
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

            $turn->refresh();
            self::assertSame(CompanionTurnStatus::Failed, $turn->status);
            self::assertSame('media_group_incomplete', $turn->failure_code);
            self::assertSame(0, $engine->calls);
            self::assertNotNull($turn->album_recovery_message_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_media_group_item_invalidates_an_incomplete_result_without_repeating_ai(): void
    {
        $engine = new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: ['decision' => 'reply', 'reply' => 'Один альбом', 'handoff_reason' => '', 'suggested_safe_actions' => []],
        ));
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $turn = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'первый',
            idempotencyKey: null,
            originExternalId: 'album-late:1',
            transportChatId: 'late-chat',
            locale: 'ru',
            mediaGroupId: 'album-late',
            sourceOrdinal: 1,
        );
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
        $late = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'поздний',
            idempotencyKey: null,
            originExternalId: 'album-late:2',
            transportChatId: 'late-chat',
            locale: 'ru',
            mediaGroupId: 'album-late',
            sourceOrdinal: 2,
        );

        self::assertSame($turn->getKey(), $late->getKey());
        self::assertSame(1, $engine->calls);
        self::assertSame(1, CompanionTurn::query()->where('media_group_id', 'album-late')->count());
        self::assertSame(1, CompanionTurnMessage::query()->where('turn_id', $turn->getKey())->count());
        self::assertNotNull($turn->fresh()->album_recovery_message_id);
        self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertStringContainsString(
            'Фотоальбом получен не полностью',
            app(CompanionMessageBodyReader::class)->read(
                $this->organization->getKey(),
                ConversationMessage::query()->findOrFail($turn->fresh()->album_recovery_message_id),
            ),
        );
        self::assertSame('late_media_group_item', ConversationMessage::query()->where('external_id', 'album-late:2')->firstOrFail()->metadata['ingest_state'] ?? null);
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

    private function acceptAlbumItem(string $externalId, string $mediaGroupId, string $body, int $sourceOrdinal): CompanionTurn
    {
        return app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: $body,
            idempotencyKey: null,
            originExternalId: $externalId,
            transportChatId: 'album-chat',
            locale: 'ru',
            mediaGroupId: $mediaGroupId,
            sourceOrdinal: $sourceOrdinal,
        );
    }
}

final class RecordingCompanionEngine implements AiWorkflowEngine
{
    public int $calls = 0;

    public ?AiRunRequest $request = null;

    public function __construct(
        private readonly AiRunResult $result,
        private readonly ?\Closure $beforeReturn = null,
    ) {}

    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        $this->calls++;
        $this->request = $request;
        if ($this->beforeReturn !== null) {
            ($this->beforeReturn)();
        }

        return $this->result;
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        return $this->result;
    }
}

final class InterleavingCompanionEngine implements AiWorkflowEngine
{
    public int $calls = 0;

    public function __construct(
        private readonly AiRunResult $result,
        private readonly \Closure $beforeReturn,
    ) {}

    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        $this->calls++;
        ($this->beforeReturn)();

        return $this->result;
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        return $this->result;
    }
}

final class ThrowingInterleavingEngine implements AiWorkflowEngine
{
    public function __construct(private readonly \Closure $beforeThrow) {}

    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        ($this->beforeThrow)();
        throw new \RuntimeException('provider unavailable');
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        throw new \RuntimeException('provider unavailable');
    }
}

class RecordingCompanionChannel implements MessagingChannel
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

final class RetryableCompanionChannel extends RecordingCompanionChannel
{
    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        $this->chunks[] = $chunk;

        return NotificationDeliveryResult::retryable('provider_rejected_before_acceptance');
    }
}

final class CrashAfterExternalSideEffectCompanionChannel extends RecordingCompanionChannel
{
    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        $this->chunks[] = $chunk;
        throw new \RuntimeException('Telegram accepted the message before the worker crashed.');
    }
}

final class LeaseReplacingCompanionChannel extends RecordingCompanionChannel
{
    public function __construct(private readonly int $deliveryId) {}

    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        $this->chunks[] = $chunk;
        CompanionDelivery::query()->whereKey($this->deliveryId)->update([
            'processing_lease_token' => 'new-delivery-worker',
            'processing_lease_expires_at' => now()->addMinutes(5),
        ]);

        return NotificationDeliveryResult::delivered('provider-message-1');
    }
}
