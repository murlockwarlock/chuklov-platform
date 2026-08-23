<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Infrastructure\Telegram\TelegramCompanionFormatter;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionFailureCode;
use App\Modules\ClientCompanion\Domain\Enums\CompanionSafeAction;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Infrastructure\Jobs\CompanionTypingHeartbeatJob;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\ClientCompanion\Infrastructure\Jobs\ProcessCompanionTurn;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CompanionTurnProcessor
{
    public function __construct(
        private readonly AiWorkflowEngine $engine,
        private readonly AssembleCompanionContext $contextAssembler,
        private readonly CompanionResponseContract $responseContract,
        private readonly CompanionSafetyClassifier $safetyClassifier,
        private readonly RecordCompanionMessage $recordMessage,
        private readonly MessagingChannel $channel,
        private readonly TelegramCompanionFormatter $formatter,
    ) {}

    public function handle(int $organizationId, int $turnId): void
    {
        $claimed = $this->claim($organizationId, $turnId);
        if ($claimed === null) {
            return;
        }

        if ($claimed['wait']) {
            if (config('queue.default') !== 'sync') {
                ProcessCompanionTurn::dispatch($organizationId, $turnId)
                    ->delay(now()->addSecond());
            }

            return;
        }

        /** @var CompanionTurn $turn */
        $turn = $claimed['turn'];
        /** @var Conversation $conversation */
        $conversation = $claimed['conversation'];
        $leaseToken = $claimed['lease_token'];
        $this->startTyping($turn);
        $locale = 'en';

        try {
            $inbound = $turn->inboundMessage()->firstOrFail();
            $locale = (string) ($inbound->metadata['locale'] ?? 'en');
            $inputFailure = CompanionFailureCode::tryFrom((string) $turn->input_failure_code);
            if ($inputFailure instanceof CompanionFailureCode
                && in_array($inputFailure, [CompanionFailureCode::ImageUnavailable, CompanionFailureCode::InputLimitExceeded], true)) {
                $this->failSafely($organizationId, $turn->getKey(), $leaseToken, $inputFailure, $locale);

                return;
            }
            $context = $this->contextAssembler->handle($organizationId, $conversation, $turn);
            $directReason = $this->safetyClassifier->classify($context['current_message']);
            if ($directReason !== null) {
                $this->handoff($organizationId, $turn->getKey(), $leaseToken, $directReason, null, $locale);

                return;
            }

            $result = $this->engine->run($organizationId, new AiRunRequest(
                capability: AiCapability::ClientCompanion,
                workflowKey: 'client_companion',
                origin: AiRunOrigin::ClientCompanion,
                executionMode: AiExecutionMode::Sync,
                clientId: (int) $turn->client_id,
                inputVariables: [
                    'current_message' => $context['current_message'],
                    'conversation_history' => $context['conversation_history'],
                    'rag_query' => $context['current_message'],
                ],
                inputReferences: array_merge(
                    [new AiInputReference('client', (int) $turn->client_id)],
                    array_map(
                        static fn (int $id): AiInputReference => new AiInputReference('companion_attachment', $id),
                        $context['attachment_ids'],
                    ),
                ),
                requiredModalities: $context['required_modalities'],
                idempotencyKey: 'companion-turn:'.$turn->getKey(),
                timeoutSeconds: 120,
            ));

            if ($result->runId > 0) {
                if (! $this->attachRun($organizationId, $turn->getKey(), $leaseToken, $result->runId)) {
                    return;
                }
            }

            $response = $this->responseContract->parse($result);
            if ($response['decision'] === 'handoff_required') {
                $reason = $this->reasonFromModel($response['handoff_reason']);
                $this->handoff($organizationId, $turn->getKey(), $leaseToken, $reason, $result->runId, $locale);

                return;
            }

            $this->complete($organizationId, $turn->getKey(), $leaseToken, $response['reply'], $locale, $response['suggested_safe_actions']);
        } catch (Throwable $exception) {
            $this->failSafely($organizationId, $turn->getKey(), $leaseToken, $this->failureCode($exception), $locale);
        }
    }

    public function handleFailureFromQueue(int $organizationId, int $turnId): void
    {
        $claimed = $this->claim($organizationId, $turnId);
        if ($claimed === null || $claimed['wait']) {
            return;
        }

        $turn = $claimed['turn'];
        $locale = (string) ($turn->inboundMessage()->first()?->metadata['locale'] ?? 'en');
        $this->failSafely($organizationId, $turnId, $claimed['lease_token'], CompanionFailureCode::QueueFailure, $locale);
    }

    /** @return array{wait: true, turn: CompanionTurn, conversation: Conversation, lease_token: ''}|array{wait: false, turn: CompanionTurn, conversation: Conversation, lease_token: non-empty-string}|null */
    private function claim(int $organizationId, int $turnId): ?array
    {
        $token = (string) Str::uuid();

        return DB::transaction(function () use ($organizationId, $turnId, $token): ?array {
            $aggregate = $this->lockTurnAggregate($organizationId, $turnId);
            if ($aggregate === null) {
                return null;
            }
            $turn = $aggregate['turn'];
            $conversation = $aggregate['conversation'];
            if ($turn->status->isTerminal()) {
                return null;
            }

            if ((int) $conversation->context_epoch !== (int) $turn->context_epoch) {
                $turn->update([
                    'status' => CompanionTurnStatus::Cancelled,
                    'typing_active' => false,
                    'processing_lease_token' => null,
                    'processing_lease_expires_at' => null,
                    'typing_owner_token' => null,
                    'typing_chat_id' => null,
                    'completed_at' => now(),
                ]);

                return null;
            }

            if ($conversation->automation_state === ConversationAutomationState::HumanHandoff) {
                $turn->update([
                    'status' => CompanionTurnStatus::Paused,
                    'typing_active' => false,
                    'processing_lease_token' => null,
                    'processing_lease_expires_at' => null,
                    'typing_owner_token' => null,
                    'typing_chat_id' => null,
                ]);

                return null;
            }

            if ($turn->status === CompanionTurnStatus::Processing && ! $turn->leaseIsExpired()) {
                return null;
            }

            if ($turn->status === CompanionTurnStatus::Assembling) {
                if ($turn->burst_expires_at !== null && $turn->burst_expires_at->isFuture()) {
                    return ['wait' => true, 'turn' => $turn, 'conversation' => $conversation, 'lease_token' => ''];
                }

                $turn->update([
                    'status' => CompanionTurnStatus::Pending,
                    'sealed_at' => $turn->sealed_at ?? now(),
                ]);
                $turn->refresh();
            }

            if ($turn->status === CompanionTurnStatus::Pending
                && $turn->burst_expires_at !== null
                && $turn->burst_expires_at->isFuture()) {
                return ['wait' => true, 'turn' => $turn, 'conversation' => $conversation, 'lease_token' => ''];
            }

            $earlier = CompanionTurn::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversation->getKey())
                ->where('context_epoch', $turn->context_epoch)
                ->where('sequence', '<', $turn->sequence)
                ->whereIn('status', [
                    CompanionTurnStatus::Assembling->value,
                    CompanionTurnStatus::Pending->value,
                    CompanionTurnStatus::Processing->value,
                ])
                ->orderBy('sequence')
                ->first();
            if ($earlier instanceof CompanionTurn) {
                return ['wait' => true, 'turn' => $turn, 'conversation' => $conversation, 'lease_token' => ''];
            }

            $typing = $turn->origin_channel === 'telegram' && $turn->transport_chat_id !== null;
            $turn->update([
                'status' => CompanionTurnStatus::Processing,
                'processing_lease_token' => $token,
                'processing_lease_expires_at' => now()->addSeconds((int) config('ai.companion.processing_lease_seconds', 180)),
                'processing_started_at' => $turn->processing_started_at ?? now(),
                'sealed_at' => $turn->sealed_at ?? now(),
                'typing_owner_token' => $typing ? $token : null,
                'typing_heartbeat_sequence' => 0,
                'typing_active' => $typing,
                'typing_chat_id' => $typing ? $turn->transport_chat_id : null,
            ]);

            $freshTurn = $turn->fresh();
            $freshConversation = $conversation->fresh();
            if (! $freshTurn instanceof CompanionTurn || ! $freshConversation instanceof Conversation) {
                return null;
            }

            return [
                'wait' => false,
                'turn' => $freshTurn,
                'conversation' => $freshConversation,
                'lease_token' => $token,
            ];
        });
    }

    private function startTyping(CompanionTurn $turn): void
    {
        if (! $turn->typing_active || $turn->typing_chat_id === null || $turn->typing_owner_token === null) {
            return;
        }

        try {
            $this->channel->sendTyping($turn->typing_chat_id);
        } catch (Throwable) {
        }

        if (config('queue.default') !== 'sync') {
            CompanionTypingHeartbeatJob::dispatch(
                (int) $turn->organization_id,
                (int) $turn->getKey(),
                $turn->typing_owner_token,
                0,
            )->delay(now()->addSeconds(max(3, (int) config('ai.companion.typing_heartbeat_seconds', 4))));
        }
    }

    private function attachRun(int $organizationId, int $turnId, string $leaseToken, int $runId): bool
    {
        return DB::transaction(function () use ($organizationId, $turnId, $leaseToken, $runId): bool {
            $aggregate = $this->lockTurnAggregate($organizationId, $turnId);
            if ($aggregate === null) {
                return false;
            }
            $turn = $aggregate['turn'];
            $conversation = $aggregate['conversation'];
            if (! $this->ownsProcessingLease($turn, $leaseToken)
                || (int) $conversation->context_epoch !== (int) $turn->context_epoch
                || $conversation->automation_state !== ConversationAutomationState::AiActive) {
                return false;
            }

            $turn->update(['ai_run_id' => $runId]);

            return true;
        });
    }

    /** @param list<string> $safeActions */
    private function complete(int $organizationId, int $turnId, string $leaseToken, string $reply, string $locale, array $safeActions = []): void
    {
        $deliveryIds = DB::transaction(function () use ($organizationId, $turnId, $leaseToken, $reply, $locale, $safeActions): array {
            $aggregate = $this->lockTurnAggregate($organizationId, $turnId);
            if ($aggregate === null) {
                return [];
            }
            $turn = $aggregate['turn'];
            $conversation = $aggregate['conversation'];
            if (! $this->ownsProcessingLease($turn, $leaseToken)
                || $conversation->automation_state !== ConversationAutomationState::AiActive
                || (int) $conversation->context_epoch !== (int) $turn->context_epoch) {
                if ($this->ownsProcessingLease($turn, $leaseToken)) {
                    $this->cancelOwnedTurn($turn, $conversation->automation_state === ConversationAutomationState::HumanHandoff);
                }

                return [];
            }

            $safeActions = array_values(array_unique(array_filter($safeActions, static fn (string $action): bool => CompanionSafeAction::tryFrom($action) !== null
                && $action !== CompanionSafeAction::ReinspectRecentImage->value)));
            if ($turn->input_modality === 'image') {
                $safeActions[] = CompanionSafeAction::ReinspectRecentImage->value;
            }

            $message = $this->recordMessage->handle(
                organizationId: $organizationId,
                client: Client::query()->where('organization_id', $organizationId)->whereKey($turn->client_id)->firstOrFail(),
                conversation: $conversation,
                channel: $turn->origin_channel,
                direction: ConversationDirection::Outbound,
                authorType: ConversationAuthorType::Ai,
                body: $reply,
                contextEpoch: $turn->context_epoch,
                metadata: [
                    'message_type' => 'companion_reply',
                    'locale' => $locale,
                    'transport' => $turn->origin_channel,
                    'safe_actions' => implode(',', $safeActions),
                ],
            );
            $deliveryIds = $this->createDeliveries($organizationId, $turn, $message, $reply);
            $turn->update([
                'outbound_message_id' => $message->getKey(),
                'status' => CompanionTurnStatus::Completed,
                'typing_active' => false,
                'typing_owner_token' => null,
                'typing_chat_id' => null,
                'processing_lease_token' => null,
                'processing_lease_expires_at' => null,
                'completed_at' => now(),
            ]);

            return $deliveryIds;
        });

        $this->dispatchDeliveries($organizationId, $deliveryIds);
    }

    private function handoff(int $organizationId, int $turnId, string $leaseToken, CompanionEscalationReason $reason, ?int $aiRunId, string $locale): void
    {
        $deliveryIds = DB::transaction(function () use ($organizationId, $turnId, $leaseToken, $reason, $aiRunId, $locale): array {
            $aggregate = $this->lockTurnAggregate($organizationId, $turnId);
            if ($aggregate === null) {
                return [];
            }
            $turn = $aggregate['turn'];
            $conversation = $aggregate['conversation'];
            if (! $this->ownsProcessingLease($turn, $leaseToken)
                || $conversation->automation_state !== ConversationAutomationState::AiActive
                || (int) $conversation->context_epoch !== (int) $turn->context_epoch) {
                if ($this->ownsProcessingLease($turn, $leaseToken)) {
                    $this->cancelOwnedTurn($turn, $conversation->automation_state === ConversationAutomationState::HumanHandoff);
                }

                return [];
            }
            $client = Client::query()->where('organization_id', $organizationId)->whereKey($turn->client_id)->firstOrFail();
            $message = CompanionClientMessage::from($locale);
            $outbound = $this->recordMessage->handle(
                organizationId: $organizationId,
                client: $client,
                conversation: $conversation,
                channel: $turn->origin_channel,
                direction: ConversationDirection::Outbound,
                authorType: ConversationAuthorType::Ai,
                body: $message->handoff,
                contextEpoch: $turn->context_epoch,
                metadata: ['message_type' => 'handoff', 'locale' => $message->locale, 'transport' => $turn->origin_channel],
            );
            $deliveryIds = $this->createDeliveries($organizationId, $turn, $outbound, $message->handoff);
            $turn->update([
                'ai_run_id' => $aiRunId ?? $turn->ai_run_id,
                'outbound_message_id' => $outbound->getKey(),
                'status' => CompanionTurnStatus::Escalated,
                'failure_code' => null,
                'typing_active' => false,
                'typing_owner_token' => null,
                'typing_chat_id' => null,
                'processing_lease_token' => null,
                'processing_lease_expires_at' => null,
                'escalated_at' => now(),
            ]);
            $conversation->update(['automation_state' => ConversationAutomationState::HumanHandoff]);
            CompanionEscalation::query()->create([
                'organization_id' => $organizationId,
                'client_id' => $turn->client_id,
                'conversation_id' => $conversation->getKey(),
                'turn_id' => $turn->getKey(),
                'ai_run_id' => $aiRunId ?? $turn->ai_run_id,
                'reason' => $reason,
                'status' => CompanionEscalationStatus::Open,
                'safe_metadata' => ['origin_channel' => $turn->origin_channel, 'sequence' => $turn->sequence],
                'opened_at' => now(),
            ]);

            return $deliveryIds;
        });

        $this->dispatchDeliveries($organizationId, $deliveryIds);
    }

    private function failSafely(int $organizationId, int $turnId, string $leaseToken, CompanionFailureCode $failureCode, string $locale): void
    {
        $currentTurn = CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->whereKey($turnId)
            ->first();
        $shouldHandoff = $currentTurn instanceof CompanionTurn && in_array($failureCode, [
            CompanionFailureCode::ProviderUnavailable,
            CompanionFailureCode::InvalidOutput,
            CompanionFailureCode::RetrievalFailure,
            CompanionFailureCode::QueueFailure,
        ], true) && CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $currentTurn->client_id)
            ->where('conversation_id', $currentTurn->conversation_id)
            ->where('id', '<>', $turnId)
            ->where('status', CompanionTurnStatus::Failed)
            ->where('failure_code', $failureCode->value)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
        if ($shouldHandoff) {
            $this->handoff($organizationId, $turnId, $leaseToken, CompanionEscalationReason::RepeatedExecutionFailure, null, $locale);

            return;
        }

        $deliveryIds = DB::transaction(function () use ($organizationId, $turnId, $leaseToken, $failureCode, $locale): array {
            $aggregate = $this->lockTurnAggregate($organizationId, $turnId);
            if ($aggregate === null) {
                return [];
            }
            $turn = $aggregate['turn'];
            $conversation = $aggregate['conversation'];
            if (! $this->ownsProcessingLease($turn, $leaseToken)
                || $conversation->automation_state !== ConversationAutomationState::AiActive
                || (int) $conversation->context_epoch !== (int) $turn->context_epoch) {
                if ($this->ownsProcessingLease($turn, $leaseToken)) {
                    $this->cancelOwnedTurn($turn, $conversation->automation_state === ConversationAutomationState::HumanHandoff);
                }

                return [];
            }
            $client = Client::query()->where('organization_id', $organizationId)->whereKey($turn->client_id)->firstOrFail();
            $message = CompanionClientMessage::from($locale);
            $failureMessage = match ($failureCode) {
                CompanionFailureCode::ImageUnavailable => $message->imageFailure(),
                CompanionFailureCode::InputLimitExceeded => $message->imageLimitFailure(),
                default => $message->failure,
            };
            $outbound = $this->recordMessage->handle(
                organizationId: $organizationId,
                client: $client,
                conversation: $conversation,
                channel: $turn->origin_channel,
                direction: ConversationDirection::Outbound,
                authorType: ConversationAuthorType::Ai,
                body: $failureMessage,
                contextEpoch: $turn->context_epoch,
                metadata: ['message_type' => 'terminal_failure', 'locale' => $message->locale, 'transport' => $turn->origin_channel],
            );
            $deliveryIds = $this->createDeliveries($organizationId, $turn, $outbound, $failureMessage);
            $turn->update([
                'outbound_message_id' => $outbound->getKey(),
                'status' => CompanionTurnStatus::Failed,
                'failure_code' => $failureCode,
                'typing_active' => false,
                'typing_owner_token' => null,
                'typing_chat_id' => null,
                'processing_lease_token' => null,
                'processing_lease_expires_at' => null,
                'failed_at' => now(),
            ]);

            return $deliveryIds;
        });

        $this->dispatchDeliveries($organizationId, $deliveryIds);
    }

    /** @return list<int> */
    private function createDeliveries(int $organizationId, CompanionTurn $turn, ConversationMessage $message, string $semanticText): array
    {
        if ($turn->origin_channel !== 'telegram' || $turn->transport_chat_id === null) {
            return [];
        }

        $chunks = $this->formatter->chunks($semanticText);
        $ids = [];
        foreach ($chunks as $index => $chunk) {
            $delivery = CompanionDelivery::query()->firstOrCreate([
                'organization_id' => $organizationId,
                'turn_id' => $turn->getKey(),
                'chunk_index' => $index,
            ], [
                'conversation_message_id' => $message->getKey(),
                'channel' => 'telegram',
                'recipient_external_id' => $turn->transport_chat_id,
                'chunk_count' => count($chunks),
                'status' => CompanionDeliveryStatus::Pending,
                'attempt_count' => 0,
            ]);
            $ids[] = (int) $delivery->getKey();
        }

        return $ids;
    }

    /** @param list<int> $deliveryIds */
    private function dispatchDeliveries(int $organizationId, array $deliveryIds): void
    {
        $firstDeliveryId = $deliveryIds[0] ?? null;
        if ($firstDeliveryId !== null) {
            DeliverCompanionMessage::dispatch($organizationId, $firstDeliveryId)->afterCommit();
        }
    }

    private function ownsProcessingLease(CompanionTurn $turn, string $leaseToken): bool
    {
        return $turn->status === CompanionTurnStatus::Processing
            && $leaseToken !== ''
            && ! $turn->leaseIsExpired()
            && hash_equals((string) $turn->processing_lease_token, $leaseToken);
    }

    /** @return array{turn: CompanionTurn, conversation: Conversation}|null */
    private function lockTurnAggregate(int $organizationId, int $turnId): ?array
    {
        $candidate = CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->whereKey($turnId)
            ->first();
        if (! $candidate instanceof CompanionTurn) {
            return null;
        }

        $conversation = Conversation::query()
            ->where('organization_id', $organizationId)
            ->whereKey($candidate->conversation_id)
            ->where('client_id', $candidate->client_id)
            ->lockForUpdate()
            ->first();
        if (! $conversation instanceof Conversation) {
            return null;
        }

        $turn = CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->whereKey($turnId)
            ->where('conversation_id', $conversation->getKey())
            ->where('client_id', $conversation->client_id)
            ->lockForUpdate()
            ->first();
        if (! $turn instanceof CompanionTurn) {
            return null;
        }

        return ['turn' => $turn, 'conversation' => $conversation];
    }

    private function cancelOwnedTurn(CompanionTurn $turn, bool $paused): void
    {
        $turn->update([
            'status' => $paused ? CompanionTurnStatus::Paused : CompanionTurnStatus::Cancelled,
            'typing_active' => false,
            'typing_owner_token' => null,
            'typing_chat_id' => null,
            'processing_lease_token' => null,
            'processing_lease_expires_at' => null,
            'completed_at' => $paused ? null : now(),
        ]);
    }

    private function reasonFromModel(?string $reason): CompanionEscalationReason
    {
        $normalized = mb_strtolower(trim((string) $reason));

        return match (true) {
            str_contains($normalized, 'urgent'), str_contains($normalized, 'сроч') => CompanionEscalationReason::UrgentSafetyConcern,
            str_contains($normalized, 'human'), str_contains($normalized, 'специал') => CompanionEscalationReason::HumanRequested,
            default => CompanionEscalationReason::OutOfScope,
        };
    }

    private function failureCode(Throwable $exception): CompanionFailureCode
    {
        $message = mb_strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'budget') => CompanionFailureCode::BudgetUnavailable,
            str_contains($message, 'retrieval'), str_contains($message, 'knowledge') => CompanionFailureCode::RetrievalFailure,
            str_contains($message, 'response contract'), str_contains($message, 'empty') => CompanionFailureCode::InvalidOutput,
            default => CompanionFailureCode::ProviderUnavailable,
        };
    }
}
