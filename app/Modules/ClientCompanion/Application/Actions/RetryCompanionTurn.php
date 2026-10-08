<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\ClientCompanion\Domain\Enums\CompanionFailureCode;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnAttemptStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Enums\RetryCompanionTurnResult;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnAttempt;
use App\Modules\ClientCompanion\Infrastructure\Jobs\ProcessCompanionTurn;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RetryCompanionTurn
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Client $client, int $failureMessageId): RetryCompanionTurnResult
    {
        $organizationId = $this->context->id();
        if ((int) $client->organization_id !== $organizationId) {
            throw new AuthorizationException('The Companion action is outside the organization.');
        }

        $reference = ConversationMessage::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->whereKey($failureMessageId)
            ->first();
        if (! $reference instanceof ConversationMessage
            || ! $reference->conversation()->where('conversation_type', ConversationType::ClientCompanion)->exists()) {
            throw new AuthorizationException('The Companion action is not available.');
        }

        $result = DB::transaction(function () use ($organizationId, $client, $failureMessageId, $reference): array {
            $conversation = Conversation::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->whereKey($reference->conversation_id)
                ->lockForUpdate()
                ->firstOrFail();
            $message = ConversationMessage::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->whereKey($failureMessageId)
                ->lockForUpdate()
                ->firstOrFail();
            $turnId = CompanionTurnAttempt::query()
                ->where('organization_id', $organizationId)
                ->where('output_message_id', $message->getKey())
                ->value('turn_id');
            $turn = CompanionTurn::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversation->getKey())
                ->when($turnId !== null, fn ($query) => $query->whereKey($turnId), fn ($query) => $query->where('outbound_message_id', $message->getKey()))
                ->lockForUpdate()
                ->first();
            if (! $turn instanceof CompanionTurn) {
                throw new AuthorizationException('The Companion action is not available.');
            }
            $alreadyRequested = CompanionTurnAttempt::query()
                ->where('organization_id', $organizationId)
                ->where('turn_id', $turn->getKey())
                ->where('retry_requested_from_message_id', $failureMessageId)
                ->lockForUpdate()
                ->first();
            if ($alreadyRequested instanceof CompanionTurnAttempt) {
                return [
                    'result' => in_array($alreadyRequested->status, [CompanionTurnAttemptStatus::Pending, CompanionTurnAttemptStatus::Processing], true)
                        ? RetryCompanionTurnResult::AlreadyRequested
                        : RetryCompanionTurnResult::Unavailable,
                    'turnId' => $alreadyRequested->status === CompanionTurnAttemptStatus::Pending ? (int) $turn->getKey() : null,
                ];
            }

            if ($turn->status !== CompanionTurnStatus::Failed
                || $conversation->automation_state !== ConversationAutomationState::AiActive
                || (int) $turn->context_epoch !== (int) $conversation->context_epoch) {
                return ['result' => RetryCompanionTurnResult::Unavailable, 'turnId' => null];
            }

            $sourceAttempt = CompanionTurnAttempt::query()
                ->where('organization_id', $organizationId)
                ->where('turn_id', $turn->getKey())
                ->where('output_message_id', $message->getKey())
                ->lockForUpdate()
                ->first();
            if ($sourceAttempt instanceof CompanionTurnAttempt) {
                $failureCode = CompanionFailureCode::tryFrom((string) $sourceAttempt->failure_code);
                if ($sourceAttempt->status !== CompanionTurnAttemptStatus::Failed
                    || ! $failureCode instanceof CompanionFailureCode
                    || ! $failureCode->isRetryable()) {
                    return ['result' => RetryCompanionTurnResult::Unavailable, 'turnId' => null];
                }
            } else {
                $failureCode = CompanionFailureCode::tryFrom((string) $turn->failure_code);
                if ($turn->outbound_message_id !== $message->getKey()
                    || ! $failureCode instanceof CompanionFailureCode
                    || ! $failureCode->isRetryable()) {
                    return ['result' => RetryCompanionTurnResult::Unavailable, 'turnId' => null];
                }
                $sourceAttempt = CompanionTurnAttempt::query()->create([
                    'organization_id' => $organizationId,
                    'turn_id' => $turn->getKey(),
                    'attempt_number' => 1,
                    'execution_key' => 'legacy-companion-turn:'.$turn->getKey(),
                    'status' => CompanionTurnAttemptStatus::Failed,
                    'ai_run_id' => $turn->ai_run_id,
                    'failure_code' => $failureCode,
                    'output_message_id' => $message->getKey(),
                    'execution_deadline_at' => $turn->execution_deadline_at,
                    'started_at' => $turn->processing_started_at,
                    'completed_at' => $turn->failed_at,
                ]);
            }

            $failureAt = $sourceAttempt->completed_at ?? $turn->failed_at;
            if ($conversation->last_human_takeover_at !== null
                && ($failureAt === null || $failureAt->lessThanOrEqualTo($conversation->last_human_takeover_at))) {
                return ['result' => RetryCompanionTurnResult::Unavailable, 'turnId' => null];
            }

            $laterTurnExists = CompanionTurn::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversation->getKey())
                ->where('context_epoch', $turn->context_epoch)
                ->where('sequence', '>', $turn->sequence)
                ->exists();
            if ($laterTurnExists) {
                return ['result' => RetryCompanionTurnResult::Unavailable, 'turnId' => null];
            }

            $nextAttemptNumber = (int) CompanionTurnAttempt::query()
                ->where('organization_id', $organizationId)
                ->where('turn_id', $turn->getKey())
                ->max('attempt_number') + 1;
            CompanionTurnAttempt::query()->create([
                'organization_id' => $organizationId,
                'turn_id' => $turn->getKey(),
                'attempt_number' => $nextAttemptNumber,
                'execution_key' => (string) Str::uuid(),
                'status' => CompanionTurnAttemptStatus::Pending,
                'retry_requested_from_message_id' => $failureMessageId,
            ]);
            $turn->update([
                'status' => CompanionTurnStatus::Pending,
                'failure_code' => null,
                'ai_run_id' => null,
                'execution_deadline_at' => null,
                'processing_lease_token' => null,
                'processing_lease_expires_at' => null,
                'typing_owner_token' => null,
                'typing_active' => false,
                'typing_chat_id' => null,
                'processing_started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'escalated_at' => null,
            ]);

            return ['result' => RetryCompanionTurnResult::Queued, 'turnId' => (int) $turn->getKey()];
        });

        if ($result['turnId'] !== null) {
            ProcessCompanionTurn::dispatch($organizationId, $result['turnId'])->afterCommit();
        }

        return $result['result'];
    }
}
