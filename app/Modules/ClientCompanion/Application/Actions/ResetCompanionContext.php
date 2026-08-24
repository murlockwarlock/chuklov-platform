<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Models\User;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ResetCompanionContext
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handleForClient(Client $client): void
    {
        $organization = $this->context->organization();
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The Companion conversation is outside the organization.');
        }
        $this->reset($organization->getKey(), $client, null);
    }

    public function handleForStaff(User $actor, Client $client): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageCompanionHandoff);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The Companion conversation is outside the organization.');
        }
        $this->reset($organization->getKey(), $client, $actor);
    }

    private function reset(int $organizationId, Client $client, ?User $actor): void
    {
        $conversation = DB::transaction(function () use ($organizationId, $client): ?Conversation {
            $conversation = Conversation::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->lockForUpdate()
                ->first();
            if (! $conversation instanceof Conversation) {
                return null;
            }

            $nextEpoch = (int) $conversation->context_epoch + 1;
            $conversation->update(['context_epoch' => $nextEpoch]);
            CompanionTurn::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversation->getKey())
                ->where('context_epoch', '<', $nextEpoch)
                ->whereIn('status', ['assembling', 'pending', 'processing', 'paused'])
                ->update([
                    'status' => 'cancelled',
                    'typing_active' => false,
                    'processing_lease_token' => null,
                    'processing_lease_expires_at' => null,
                    'typing_owner_token' => null,
                    'typing_chat_id' => null,
                    'completed_at' => now(),
                ]);

            return $conversation->refresh();
        });

        if ($conversation instanceof Conversation) {
            $this->audit->handle(
                organization: $this->context->organization(),
                actor: $actor,
                action: 'companion.context.reset',
                targetType: Conversation::class,
                targetId: (string) $conversation->getKey(),
                metadata: ['new_epoch' => $conversation->context_epoch],
            );
        }
    }
}
