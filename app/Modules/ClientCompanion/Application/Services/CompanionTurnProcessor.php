<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Exceptions\AiProviderUnavailableException;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Services\AiErrorSanitizer;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Infrastructure\Telegram\TelegramCompanionFormatter;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionFailureCode;
use App\Modules\ClientCompanion\Domain\Enums\CompanionSafeAction;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnAttemptStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnAttempt;
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
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Jobs\ProcessScenarioEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
        private readonly EnsureOperationalNotificationDefaults $notificationDefaults,
        private readonly RecordScenarioEvent $scenarioEvents,
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
        /** @var CompanionTurnAttempt $attempt */
        $attempt = $claimed['attempt'];
        $leaseToken = $claimed['lease_token'];
        $this->startTyping($turn);
        $locale = 'en';
        $result = null;

        try {
            $inbound = $turn->inboundMessage()->firstOrFail();
            $locale = (string) ($inbound->metadata['locale'] ?? 'en');
            $inputFailure = CompanionFailureCode::tryFrom((string) $turn->input_failure_code);
            if ($inputFailure instanceof CompanionFailureCode
                && in_array($inputFailure, [
                    CompanionFailureCode::ImageUnavailable,
                    CompanionFailureCode::DocumentUnavailable,
                    CompanionFailureCode::InputLimitExceeded,
                    CompanionFailureCode::MediaGroupIncomplete,
                ], true)) {
                $this->failSafely($organizationId, $turn->getKey(), $leaseToken, $inputFailure, $locale);

                return;
            }
            if ($turn->execution_deadline_at !== null
                && ! AiRuntimeLimits::deadlineIsActive($turn->execution_deadline_at)) {
                $this->failSafely(
                    $organizationId,
                    $turn->getKey(),
                    $leaseToken,
                    CompanionFailureCode::ExecutionDeadlineExceeded,
                    $locale,
                );

                return;
            }
            $context = $this->contextAssembler->handle($organizationId, $conversation, $turn);
            $directReason = $this->safetyClassifier->classify($context['current_message']);
            if ($directReason !== null) {
                $this->openSpecialistEscalation($organizationId, $turn->getKey(), $leaseToken, $directReason, null, $locale);

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
                    'health_context' => $context['health_context'],
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
                idempotencyKey: 'companion-turn:'.$attempt->execution_key,
                timeoutSeconds: 120,
                executionDeadlineAt: $turn->execution_deadline_at,
            ));

            if ($result->runId > 0) {
                if (! $this->executionWindowIsActive($turn)) {
                    $this->failSafely(
                        $organizationId,
                        $turn->getKey(),
                        $leaseToken,
                        CompanionFailureCode::ExecutionDeadlineExceeded,
                        $locale,
                    );

                    return;
                }
                if (! $this->attachRun($organizationId, $turn->getKey(), $attempt->getKey(), $leaseToken, $result->runId)) {
                    return;
                }
            }

            if (! $result->isSuccess()) {
                $this->failSafely(
                    $organizationId,
                    $turn->getKey(),
                    $leaseToken,
                    $this->failureCodeFromResult($result),
                    $locale,
                );

                return;
            }

            $response = $this->responseContract->parse($result);
            if (! $this->executionWindowIsActive($turn)) {
                $this->failSafely(
                    $organizationId,
                    $turn->getKey(),
                    $leaseToken,
                    CompanionFailureCode::ExecutionDeadlineExceeded,
                    $locale,
                );

                return;
            }
            if ($response['decision'] === 'handoff_required') {
                if ($this->safetyClassifier->isHandoffForbidden($context['current_message'])) {
                    if ($response['reply'] !== '') {
                        $this->complete($organizationId, $turn->getKey(), $leaseToken, $response['reply'], $locale, $response['suggested_safe_actions']);

                        return;
                    }

                    throw new InvalidArgumentException('The Companion model cannot infer an explicit human request.');
                }
                $reason = $this->reasonFromModel($response['handoff_reason']);
                if ($reason === CompanionEscalationReason::HumanRequested) {
                    if ($response['reply'] !== '') {
                        $this->complete($organizationId, $turn->getKey(), $leaseToken, $response['reply'], $locale, $response['suggested_safe_actions']);

                        return;
                    }

                    throw new InvalidArgumentException('The Companion model cannot infer an explicit human request.');
                }
                if ($reason === CompanionEscalationReason::OutOfScope) {
                    $message = CompanionClientMessage::from($locale);
                    $reply = $response['reply'] !== '' ? $response['reply'] : $message->outOfScope;
                    $safeActions = array_merge($response['suggested_safe_actions'], [CompanionSafeAction::RequestHuman->value]);
                    $this->complete($organizationId, $turn->getKey(), $leaseToken, $reply, $locale, $safeActions);

                    return;
                }
                if (! $this->safetyClassifier->isAuthorizedModelHandoff($context['current_message'], $reason)) {
                    if ($response['reply'] !== '') {
                        $this->complete($organizationId, $turn->getKey(), $leaseToken, $response['reply'], $locale, $response['suggested_safe_actions']);

                        return;
                    }

                    throw new InvalidArgumentException('The Companion model cannot infer an authorized handoff.');
                }
                $this->openSpecialistEscalation($organizationId, $turn->getKey(), $leaseToken, $reason, $result->runId, $locale);

                return;
            }

            $this->complete($organizationId, $turn->getKey(), $leaseToken, $response['reply'], $locale, $response['suggested_safe_actions']);
        } catch (Throwable $exception) {
            $failureCode = $this->failureCode($exception, $result);
            $this->logExecutionFailure($organizationId, $turn, $failureCode, $result, $exception);
            $this->failSafely($organizationId, $turn->getKey(), $leaseToken, $failureCode, $locale);
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

    /** @return array{wait: true, turn: CompanionTurn, conversation: Conversation, lease_token: ''}|array{wait: false, turn: CompanionTurn, conversation: Conversation, attempt: CompanionTurnAttempt, lease_token: non-empty-string}|null */
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
                $this->cancelAttempts($organizationId, $turn);
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
                $this->cancelAttempts($organizationId, $turn);
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
                if ($turn->album_assembly_deadline_at !== null
                    && ! $turn->album_assembly_deadline_at->isFuture()
                    && $turn->input_failure_code === null) {
                    $turn->update([
                        'input_failure_code' => CompanionFailureCode::MediaGroupIncomplete->value,
                        'album_incomplete_at' => now(),
                        'burst_expires_at' => now(),
                    ]);
                    $turn->refresh();
                }
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
            $executionDeadlineAt = $turn->execution_deadline_at ?? AiRuntimeLimits::executionDeadline();
            $processingLeaseExpiresAt = $executionDeadlineAt->copy()->addSeconds(AiRuntimeLimits::PLATFORM_LEASE_GRACE_SECONDS);
            if ($executionDeadlineAt->lessThanOrEqualTo(now())) {
                $processingLeaseExpiresAt = now()->addSeconds(AiRuntimeLimits::PLATFORM_LEASE_GRACE_SECONDS);
            }
            $attempt = CompanionTurnAttempt::query()
                ->where('organization_id', $organizationId)
                ->where('turn_id', $turn->getKey())
                ->whereIn('status', [CompanionTurnAttemptStatus::Pending, CompanionTurnAttemptStatus::Processing])
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();
            if (! $attempt instanceof CompanionTurnAttempt) {
                $isLegacyProcessing = $turn->status === CompanionTurnStatus::Processing
                    && $turn->processing_started_at !== null;
                $attemptNumber = (int) CompanionTurnAttempt::query()
                    ->where('organization_id', $organizationId)
                    ->where('turn_id', $turn->getKey())
                    ->max('attempt_number') + 1;
                $attempt = CompanionTurnAttempt::query()->create([
                    'organization_id' => $organizationId,
                    'turn_id' => $turn->getKey(),
                    'attempt_number' => $attemptNumber,
                    'execution_key' => $isLegacyProcessing ? (string) $turn->getKey() : (string) Str::uuid(),
                    'status' => CompanionTurnAttemptStatus::Processing,
                    'ai_run_id' => $isLegacyProcessing ? $turn->ai_run_id : null,
                    'execution_deadline_at' => $executionDeadlineAt,
                    'started_at' => $turn->processing_started_at ?? now(),
                ]);
            } else {
                $attempt->update([
                    'status' => CompanionTurnAttemptStatus::Processing,
                    'execution_deadline_at' => $attempt->execution_deadline_at ?? $executionDeadlineAt,
                    'started_at' => $attempt->started_at ?? now(),
                ]);
            }
            $turn->update([
                'status' => CompanionTurnStatus::Processing,
                'processing_lease_token' => $token,
                'processing_lease_expires_at' => $processingLeaseExpiresAt,
                'execution_deadline_at' => $executionDeadlineAt,
                'processing_started_at' => $turn->processing_started_at ?? now(),
                'sealed_at' => $turn->sealed_at ?? now(),
                'typing_owner_token' => $typing ? $token : null,
                'typing_heartbeat_sequence' => 0,
                'typing_active' => $typing,
                'typing_chat_id' => $typing ? $turn->transport_chat_id : null,
            ]);

            $freshTurn = $turn->fresh();
            $freshConversation = $conversation->fresh();
            $freshAttempt = $attempt->fresh();
            if (! $freshTurn instanceof CompanionTurn
                || ! $freshConversation instanceof Conversation
                || ! $freshAttempt instanceof CompanionTurnAttempt) {
                return null;
            }

            return [
                'wait' => false,
                'turn' => $freshTurn,
                'conversation' => $freshConversation,
                'lease_token' => $token,
                'attempt' => $freshAttempt,
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

    private function attachRun(int $organizationId, int $turnId, int $attemptId, string $leaseToken, int $runId): bool
    {
        return DB::transaction(function () use ($organizationId, $turnId, $attemptId, $leaseToken, $runId): bool {
            $aggregate = $this->lockTurnAggregate($organizationId, $turnId);
            if ($aggregate === null) {
                return false;
            }
            $turn = $aggregate['turn'];
            $conversation = $aggregate['conversation'];
            $attempt = CompanionTurnAttempt::query()
                ->where('organization_id', $organizationId)
                ->where('turn_id', $turnId)
                ->whereKey($attemptId)
                ->lockForUpdate()
                ->first();
            if (! $attempt instanceof CompanionTurnAttempt
                || ! in_array($attempt->status, [CompanionTurnAttemptStatus::Processing, CompanionTurnAttemptStatus::Cancelled], true)) {
                return false;
            }

            $attempt->update(['ai_run_id' => $runId]);
            if (! $this->executionWindowIsActive($turn, $leaseToken)
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
            if (! $this->executionWindowIsActive($turn, $leaseToken)
                || $conversation->automation_state !== ConversationAutomationState::AiActive
                || (int) $conversation->context_epoch !== (int) $turn->context_epoch) {
                if ($this->ownsProcessingLease($turn, $leaseToken)) {
                    $this->cancelOwnedTurn($turn, $conversation->automation_state === ConversationAutomationState::HumanHandoff);
                }

                return [];
            }

            $safeActions = array_values(array_unique(array_filter($safeActions, static fn (string $action): bool => CompanionSafeAction::tryFrom($action) !== null
                && ! in_array($action, [CompanionSafeAction::ReinspectRecentImage->value, CompanionSafeAction::RetryFailedTurn->value], true))));
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
            $this->currentAttempt($organizationId, $turn)->update([
                'status' => CompanionTurnAttemptStatus::Succeeded,
                'ai_run_id' => $turn->ai_run_id,
                'output_message_id' => $message->getKey(),
                'completed_at' => now(),
            ]);

            return $deliveryIds;
        });

        $this->dispatchDeliveries($organizationId, $deliveryIds);
    }

    private function openSpecialistEscalation(int $organizationId, int $turnId, string $leaseToken, CompanionEscalationReason $reason, ?int $aiRunId, string $locale): void
    {
        $organization = Organization::query()->find($organizationId);
        if (! $organization instanceof Organization) {
            return;
        }
        $this->notificationDefaults->handle($organization);

        $escalationResult = DB::transaction(function () use ($organizationId, $turnId, $leaseToken, $reason, $aiRunId, $locale): array {
            $aggregate = $this->lockTurnAggregate($organizationId, $turnId);
            if ($aggregate === null) {
                return ['deliveryIds' => [], 'scenarioEventId' => null];
            }
            $turn = $aggregate['turn'];
            $conversation = $aggregate['conversation'];
            if (! $this->executionWindowIsActive($turn, $leaseToken)
                || $conversation->automation_state !== ConversationAutomationState::AiActive
                || (int) $conversation->context_epoch !== (int) $turn->context_epoch) {
                if ($this->ownsProcessingLease($turn, $leaseToken)) {
                    $this->cancelOwnedTurn($turn, $conversation->automation_state === ConversationAutomationState::HumanHandoff);
                }

                return ['deliveryIds' => [], 'scenarioEventId' => null];
            }
            $client = Client::query()->where('organization_id', $organizationId)->whereKey($turn->client_id)->firstOrFail();
            $message = CompanionClientMessage::from($locale);
            $body = match ($reason) {
                CompanionEscalationReason::HumanRequested => $message->specialistNotified,
                CompanionEscalationReason::UrgentSafetyConcern => $message->urgentSafety,
                CompanionEscalationReason::OutOfScope => $message->outOfScope,
                CompanionEscalationReason::RepeatedExecutionFailure => $message->unavailable,
                CompanionEscalationReason::Other => $message->specialistNotified,
            };
            $outbound = $this->recordMessage->handle(
                organizationId: $organizationId,
                client: $client,
                conversation: $conversation,
                channel: $turn->origin_channel,
                direction: ConversationDirection::Outbound,
                authorType: ConversationAuthorType::Ai,
                body: $body,
                contextEpoch: $turn->context_epoch,
                metadata: ['message_type' => 'specialist_notified', 'locale' => $message->locale, 'transport' => $turn->origin_channel],
            );
            $deliveryIds = $this->createDeliveries($organizationId, $turn, $outbound, $body);
            $turn->update([
                'ai_run_id' => $aiRunId ?? $turn->ai_run_id,
                'outbound_message_id' => $outbound->getKey(),
                'status' => CompanionTurnStatus::Completed,
                'failure_code' => null,
                'typing_active' => false,
                'typing_owner_token' => null,
                'typing_chat_id' => null,
                'processing_lease_token' => null,
                'processing_lease_expires_at' => null,
                'escalated_at' => now(),
                'completed_at' => now(),
            ]);
            $attempt = $this->currentAttempt($organizationId, $turn);
            $attempt->update([
                'status' => CompanionTurnAttemptStatus::Succeeded,
                'ai_run_id' => $turn->ai_run_id,
                'output_message_id' => $outbound->getKey(),
                'completed_at' => now(),
            ]);

            $openEscalation = CompanionEscalation::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversation->getKey())
                ->where('reason', $reason)
                ->where('status', CompanionEscalationStatus::Open)
                ->first();
            $scenarioEventId = null;
            if (! $openEscalation instanceof CompanionEscalation) {
                $escalation = CompanionEscalation::query()->create([
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
                $scenarioEvent = $this->scenarioEvents->companionEscalationRecorded($escalation, CarbonImmutable::now());
                $scenarioEventId = (int) $scenarioEvent->getKey();
            }

            return [
                'deliveryIds' => $deliveryIds,
                'scenarioEventId' => $scenarioEventId,
            ];
        });

        $this->dispatchDeliveries($organizationId, $escalationResult['deliveryIds']);
        if ($escalationResult['scenarioEventId'] !== null) {
            ProcessScenarioEvent::dispatch((int) $escalationResult['scenarioEventId'])->afterCommit();
        }
    }

    private function failSafely(int $organizationId, int $turnId, string $leaseToken, CompanionFailureCode $failureCode, string $locale): void
    {
        $scenarioEventId = null;
        $deliveryIds = DB::transaction(function () use ($organizationId, $turnId, $leaseToken, $failureCode, $locale, &$scenarioEventId): array {
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
            $safeActions = $failureCode->isRetryable()
                ? [CompanionSafeAction::RetryFailedTurn->value, CompanionSafeAction::RequestHuman->value]
                : (in_array($failureCode, [
                    CompanionFailureCode::NotConfigured,
                    CompanionFailureCode::ProviderDisabled,
                    CompanionFailureCode::ProviderMisconfigured,
                    CompanionFailureCode::BudgetUnavailable,
                ], true) ? [CompanionSafeAction::RequestHuman->value] : []);
            $failureMessage = match ($failureCode) {
                CompanionFailureCode::ImageUnavailable => $message->imageFailure(),
                CompanionFailureCode::DocumentUnavailable => $message->documentFailure(),
                CompanionFailureCode::InputLimitExceeded => $message->imageLimitFailure(),
                CompanionFailureCode::MediaGroupIncomplete => $message->albumIncomplete(),
                CompanionFailureCode::NotConfigured,
                CompanionFailureCode::ProviderDisabled,
                CompanionFailureCode::ProviderMisconfigured,
                CompanionFailureCode::BudgetUnavailable => $message->unavailable,
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
                metadata: [
                    'message_type' => 'terminal_failure',
                    'locale' => $message->locale,
                    'transport' => $turn->origin_channel,
                    'safe_actions' => implode(',', $safeActions),
                ],
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
                'album_incomplete_at' => $failureCode === CompanionFailureCode::MediaGroupIncomplete
                    ? ($turn->album_incomplete_at ?? now())
                    : $turn->album_incomplete_at,
                'album_recovery_message_id' => $failureCode === CompanionFailureCode::MediaGroupIncomplete
                    ? $outbound->getKey()
                    : $turn->album_recovery_message_id,
            ]);
            $attempt = $this->currentAttempt($organizationId, $turn);
            $attempt->update([
                'status' => CompanionTurnAttemptStatus::Failed,
                'ai_run_id' => $turn->ai_run_id,
                'failure_code' => $failureCode,
                'output_message_id' => $outbound->getKey(),
                'completed_at' => now(),
            ]);

            if ($failureCode->shouldNotifyOperations()) {
                $scenarioEvent = $this->scenarioEvents->companionFallbackFailed($turn, $attempt, $failureCode, CarbonImmutable::now());
                $scenarioEventId = (int) $scenarioEvent->getKey();
            }

            return $deliveryIds;
        });

        $this->dispatchDeliveries($organizationId, $deliveryIds);
        if ($scenarioEventId !== null) {
            $organization = Organization::query()->whereKey($organizationId)->first();
            if ($organization instanceof Organization) {
                $this->notificationDefaults->handle($organization);
            }
            ProcessScenarioEvent::dispatch($scenarioEventId)->afterCommit();
        }
    }

    /** @return list<int> */
    private function createDeliveries(int $organizationId, CompanionTurn $turn, ConversationMessage $message, string $semanticText): array
    {
        if ($turn->origin_channel !== 'telegram' || $turn->transport_chat_id === null) {
            return [];
        }

        $chunks = $this->formatter->chunks($semanticText);
        $ids = [];
        $turnHasDelivery = CompanionDelivery::query()
            ->where('organization_id', $organizationId)
            ->where('turn_id', $turn->getKey())
            ->exists();
        foreach ($chunks as $index => $chunk) {
            $delivery = CompanionDelivery::query()->firstOrCreate([
                'organization_id' => $organizationId,
                'conversation_message_id' => $message->getKey(),
                'chunk_index' => $index,
            ], [
                'turn_id' => $turnHasDelivery ? null : $turn->getKey(),
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

    private function executionWindowIsActive(CompanionTurn $turn, ?string $leaseToken = null): bool
    {
        return ($leaseToken === null || $this->ownsProcessingLease($turn, $leaseToken))
            && ($turn->execution_deadline_at === null || AiRuntimeLimits::deadlineIsActive($turn->execution_deadline_at));
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
        $this->cancelAttempts((int) $turn->organization_id, $turn);
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

    private function currentAttempt(int $organizationId, CompanionTurn $turn): CompanionTurnAttempt
    {
        return CompanionTurnAttempt::query()
            ->where('organization_id', $organizationId)
            ->where('turn_id', $turn->getKey())
            ->where('status', CompanionTurnAttemptStatus::Processing)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function cancelAttempts(int $organizationId, CompanionTurn $turn): void
    {
        CompanionTurnAttempt::query()
            ->where('organization_id', $organizationId)
            ->where('turn_id', $turn->getKey())
            ->whereIn('status', [CompanionTurnAttemptStatus::Pending, CompanionTurnAttemptStatus::Processing])
            ->update([
                'status' => CompanionTurnAttemptStatus::Cancelled,
                'completed_at' => now(),
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

    private function failureCode(Throwable $exception, mixed $result = null): CompanionFailureCode
    {
        if ($result instanceof AiRunResult
            && $result->errorCategory instanceof AiErrorCategory) {
            return match ($result->errorCategory) {
                AiErrorCategory::BudgetExceeded => CompanionFailureCode::BudgetUnavailable,
                AiErrorCategory::ConfigurationMissing => CompanionFailureCode::NotConfigured,
                AiErrorCategory::ProviderDisabled,
                AiErrorCategory::SafetyKillSwitchActive => CompanionFailureCode::ProviderDisabled,
                AiErrorCategory::AuthenticationFailed,
                AiErrorCategory::InvalidPrompt => CompanionFailureCode::ProviderMisconfigured,
                AiErrorCategory::RateLimited => CompanionFailureCode::RateLimited,
                AiErrorCategory::OutputSchemaValidationFailed => CompanionFailureCode::InvalidOutput,
                AiErrorCategory::ToolExecutionFailed => CompanionFailureCode::RetrievalFailure,
                AiErrorCategory::ExecutionTimedOut => CompanionFailureCode::ExecutionDeadlineExceeded,
                AiErrorCategory::ContextLengthExceeded => CompanionFailureCode::InputLimitExceeded,
                default => CompanionFailureCode::ProviderUnavailable,
            };
        }

        $message = mb_strtolower($exception->getMessage());

        if ($result === null && $exception instanceof InvalidArgumentException) {
            return CompanionFailureCode::NotConfigured;
        }

        return match (true) {
            $exception instanceof AiProviderUnavailableException
                && $exception->providerDisabled => CompanionFailureCode::ProviderDisabled,
            $exception instanceof AiProviderUnavailableException
                && $exception->configurationMissing,
            $exception instanceof InvalidArgumentException && (
                str_contains($message, 'tenant-owned active prompt version')
                || str_contains($message, 'selected prompt version')
                || str_contains($message, 'draft prompt versions')
            ) => CompanionFailureCode::NotConfigured,
            str_contains($message, 'budget') => CompanionFailureCode::BudgetUnavailable,
            str_contains($message, 'cannot infer an explicit human request'),
            str_contains($message, 'cannot infer an authorized handoff') => CompanionFailureCode::InvalidOutput,
            str_contains($message, 'retrieval'), str_contains($message, 'knowledge') => CompanionFailureCode::RetrievalFailure,
            str_contains($message, 'response contract'), str_contains($message, 'empty') => CompanionFailureCode::InvalidOutput,
            str_contains($message, 'active prompt'), str_contains($message, 'prompt version') => CompanionFailureCode::NotConfigured,
            default => CompanionFailureCode::ProviderUnavailable,
        };
    }

    private function failureCodeFromResult(AiRunResult $result): CompanionFailureCode
    {
        return match ($result->errorCategory) {
            AiErrorCategory::BudgetExceeded => CompanionFailureCode::BudgetUnavailable,
            AiErrorCategory::ConfigurationMissing => CompanionFailureCode::NotConfigured,
            AiErrorCategory::ProviderDisabled => CompanionFailureCode::ProviderDisabled,
            AiErrorCategory::AuthenticationFailed,
            AiErrorCategory::InvalidPrompt => CompanionFailureCode::ProviderMisconfigured,
            AiErrorCategory::OutputSchemaValidationFailed => CompanionFailureCode::InvalidOutput,
            AiErrorCategory::RateLimited => CompanionFailureCode::RateLimited,
            AiErrorCategory::ExecutionTimedOut => CompanionFailureCode::ExecutionDeadlineExceeded,
            AiErrorCategory::ToolExecutionFailed => CompanionFailureCode::RetrievalFailure,
            AiErrorCategory::ContextLengthExceeded => CompanionFailureCode::InputLimitExceeded,
            AiErrorCategory::SafetyKillSwitchActive => CompanionFailureCode::ProviderDisabled,
            AiErrorCategory::ProviderUnavailable,
            AiErrorCategory::InternalError => CompanionFailureCode::ProviderUnavailable,
            default => CompanionFailureCode::ProviderUnavailable,
        };
    }

    private function logExecutionFailure(
        int $organizationId,
        CompanionTurn $turn,
        CompanionFailureCode $failureCode,
        mixed $result,
        Throwable $exception,
    ): void {
        $run = $result instanceof AiRunResult && $result->runId > 0
            ? AiRun::query()
                ->where('organization_id', $organizationId)
                ->whereKey($result->runId)
                ->with(['promptVersion', 'modelRelease'])
                ->first()
            : null;
        $run ??= $turn->ai_run_id !== null
            ? AiRun::query()
                ->where('organization_id', $organizationId)
                ->whereKey($turn->ai_run_id)
                ->with(['promptVersion', 'modelRelease'])
                ->first()
            : null;
        $activePrompt = AiPrompt::query()
            ->where('organization_id', $organizationId)
            ->where('capability', AiCapability::ClientCompanion->value)
            ->latest('id')
            ->first(['id', 'active_version_id']);
        $safeError = AiErrorSanitizer::sanitize($exception);
        $runErrorCategory = $result instanceof AiRunResult
            ? $result->errorCategory?->value
            : $run?->error_category?->value;
        $runStatus = $result instanceof AiRunResult
            ? $result->status->value
            : $run?->status?->value;
        $runPromptId = $run instanceof AiRun ? $run->promptVersion?->prompt_id : null;
        $runPromptVersionId = $run instanceof AiRun ? $run->prompt_version_id : null;
        $runProvider = $run instanceof AiRun ? $run->actual_provider : null;
        $runProvider ??= $run instanceof AiRun ? $run->requested_provider : null;
        $runProvider ??= $run instanceof AiRun ? $run->modelRelease?->provider_name : null;
        $runModel = $run instanceof AiRun ? $run->actual_model : null;
        $runModel ??= $run instanceof AiRun ? $run->requested_model : null;
        $runModel ??= $run instanceof AiRun ? $run->modelRelease?->model_name : null;

        Log::warning('client_companion_ai_failure', [
            'organization_id' => $organizationId,
            'companion_turn_id' => $turn->getKey(),
            'ai_run_id' => $run?->getKey(),
            'prompt_id' => $runPromptId ?? $activePrompt?->getKey(),
            'prompt_version_id' => $runPromptVersionId ?? $activePrompt?->active_version_id,
            'prompt_version' => $run?->promptVersion?->version,
            'model_release_id' => $run?->model_release_id,
            'model_provider' => $runProvider,
            'model_name' => $runModel,
            'model_release_number' => $run?->modelRelease?->release_number,
            'configured_model_release_ids' => AiModelRelease::query()
                ->where('organization_id', $organizationId)
                ->whereJsonContains('capabilities', AiCapability::ClientCompanion->value)
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'run_status' => $runStatus,
            'provider_error_category' => $runErrorCategory,
            'failure_code' => $failureCode->value,
            'sanitized_failure_category' => $safeError['category']->value,
            'conversation_state' => $turn->conversation()->value('automation_state'),
            'turn_status' => $turn->status->value,
            'exception_class' => $exception::class,
        ]);
    }
}
