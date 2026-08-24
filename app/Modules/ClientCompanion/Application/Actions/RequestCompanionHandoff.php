<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RequestCompanionHandoff
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Client $client, int $messageId): void
    {
        $organizationId = $this->context->id();
        if ((int) $client->organization_id !== $organizationId) {
            throw new AuthorizationException('The Companion action is outside the organization.');
        }

        DB::transaction(function () use ($organizationId, $client, $messageId): void {
            $message = ConversationMessage::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->whereKey($messageId)
                ->first();
            if ($message === null || ! $message->conversation()->where('conversation_type', ConversationType::ClientCompanion)->exists()) {
                throw new AuthorizationException('The Companion action is not available.');
            }
            $conversation = Conversation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($message->conversation_id)
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->lockForUpdate()
                ->firstOrFail();
            $message = ConversationMessage::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->whereKey($messageId)
                ->lockForUpdate()
                ->firstOrFail();
            $turn = CompanionTurn::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $message->conversation_id)
                ->where('outbound_message_id', $message->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($conversation->automation_state === ConversationAutomationState::HumanHandoff) {
                return;
            }

            $open = CompanionEscalation::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversation->getKey())
                ->where('status', CompanionEscalationStatus::Open)
                ->exists();
            if ($open) {
                return;
            }

            if (in_array($turn->status, [CompanionTurnStatus::Assembling, CompanionTurnStatus::Pending, CompanionTurnStatus::Processing], true)) {
                $turn->update([
                    'status' => CompanionTurnStatus::Escalated,
                    'typing_active' => false,
                    'processing_lease_token' => null,
                    'processing_lease_expires_at' => null,
                    'typing_owner_token' => null,
                    'typing_chat_id' => null,
                    'escalated_at' => now(),
                ]);
            }
            $conversation->update(['automation_state' => ConversationAutomationState::HumanHandoff]);
            CompanionEscalation::query()->create([
                'organization_id' => $organizationId,
                'client_id' => $client->getKey(),
                'conversation_id' => $conversation->getKey(),
                'turn_id' => $turn->getKey(),
                'ai_run_id' => $turn->ai_run_id,
                'reason' => CompanionEscalationReason::HumanRequested,
                'status' => CompanionEscalationStatus::Open,
                'safe_metadata' => ['source' => 'telegram_button'],
                'opened_at' => now(),
            ]);
        });
    }
}
