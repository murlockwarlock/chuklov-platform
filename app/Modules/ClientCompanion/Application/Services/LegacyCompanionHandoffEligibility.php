<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Security\Domain\Models\AuditEvent;

final class LegacyCompanionHandoffEligibility
{
    public function canRestore(Conversation $conversation): bool
    {
        if ($conversation->automation_state !== ConversationAutomationState::HumanHandoff
            || $conversation->last_human_takeover_at !== null) {
            return false;
        }

        $escalations = CompanionEscalation::query()
            ->where('organization_id', $conversation->organization_id)
            ->where('conversation_id', $conversation->getKey())
            ->where('status', CompanionEscalationStatus::Open)
            ->orderBy('opened_at')
            ->get(['id', 'reason', 'opened_at']);
        if ($escalations->isEmpty()
            || $escalations->contains(fn (CompanionEscalation $escalation): bool => $escalation->reason !== CompanionEscalationReason::RepeatedExecutionFailure)) {
            return false;
        }

        $earliestEscalationAt = $escalations->first()->opened_at;
        if (ConversationMessage::query()
            ->where('organization_id', $conversation->organization_id)
            ->where('conversation_id', $conversation->getKey())
            ->where('author_type', ConversationAuthorType::Staff)
            ->where('occurred_at', '>=', $earliestEscalationAt)
            ->exists()) {
            return false;
        }

        return ! AuditEvent::query()
            ->where('organization_id', $conversation->organization_id)
            ->where('target_type', Conversation::class)
            ->where('target_id', (string) $conversation->getKey())
            ->where('action', 'companion.handoff.taken_over')
            ->where('occurred_at', '>=', $earliestEscalationAt)
            ->exists();
    }
}
