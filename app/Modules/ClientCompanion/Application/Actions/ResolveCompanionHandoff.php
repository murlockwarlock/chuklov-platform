<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Models\User;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ResolveCompanionHandoff
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

        $escalation = DB::transaction(function () use ($organization, $actor, $client): ?CompanionEscalation {
            $conversation = Conversation::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->lockForUpdate()
                ->firstOrFail();
            $escalation = CompanionEscalation::query()
                ->where('organization_id', $organization->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->where('status', CompanionEscalationStatus::Open)
                ->latest('opened_at')
                ->lockForUpdate()
                ->first();
            if (! $escalation instanceof CompanionEscalation) {
                return null;
            }
            $escalation->update([
                'status' => CompanionEscalationStatus::Resolved,
                'resolved_by_user_id' => $actor->getKey(),
                'resolved_at' => now(),
            ]);

            return $escalation->refresh();
        });

        if ($escalation instanceof CompanionEscalation) {
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'companion.handoff.resolved',
                targetType: CompanionEscalation::class,
                targetId: (string) $escalation->getKey(),
                metadata: ['reason' => $escalation->reason->value],
            );
        }
    }
}
