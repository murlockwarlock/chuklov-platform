<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Models\User;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnAttemptStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnAttempt;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ResumeCompanionAi
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageCompanionHandoff);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The Companion conversation is outside the organization.');
        }

        DB::transaction(function () use ($organization, $actor, $client): void {
            $conversation = Conversation::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->lockForUpdate()
                ->firstOrFail();
            if ($conversation->automation_state !== ConversationAutomationState::HumanHandoff) {
                return;
            }
            $pausedTurns = CompanionTurn::query()
                ->where('organization_id', $organization->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->where('status', CompanionTurnStatus::Paused)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();
            foreach ($pausedTurns as $turn) {
                $turn->update(['status' => CompanionTurnStatus::Cancelled, 'completed_at' => now()]);
            }
            CompanionTurnAttempt::query()
                ->where('organization_id', $organization->getKey())
                ->whereIn('turn_id', $pausedTurns->modelKeys())
                ->whereIn('status', [CompanionTurnAttemptStatus::Pending, CompanionTurnAttemptStatus::Processing])
                ->update([
                    'status' => CompanionTurnAttemptStatus::Cancelled,
                    'completed_at' => now(),
                ]);
            $escalations = CompanionEscalation::query()
                ->where('organization_id', $organization->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->where('status', CompanionEscalationStatus::Open)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($escalations as $escalation) {
                $escalation->update([
                    'status' => CompanionEscalationStatus::Resolved,
                    'resolved_by_user_id' => $actor->getKey(),
                    'resolved_at' => now(),
                ]);
            }
            $conversation->update(['automation_state' => ConversationAutomationState::AiActive]);
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'companion.ai.resumed',
                targetType: Conversation::class,
                targetId: (string) $conversation->getKey(),
                metadata: ['source' => 'staff_action'],
            );
        });
    }
}
