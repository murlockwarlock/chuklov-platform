<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Models\User;
use App\Modules\Channels\Infrastructure\Telegram\TelegramCompanionFormatter;
use App\Modules\ClientCompanion\Application\Services\CompanionClientMessage;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnAttemptStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnAttempt;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationBinding;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
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
        private readonly RecordCompanionMessage $recordMessage,
        private readonly TelegramCompanionFormatter $formatter,
    ) {}

    public function handle(User $actor, Client $client): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageCompanionHandoff);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The Companion conversation is outside the organization.');
        }

        $deliveryId = DB::transaction(function () use ($organization, $actor, $client): ?int {
            $conversation = Conversation::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->lockForUpdate()
                ->firstOrFail();
            if ($conversation->automation_state !== ConversationAutomationState::HumanHandoff) {
                return null;
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
            $latestTurn = CompanionTurn::query()
                ->where('organization_id', $organization->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->orderByDesc('sequence')
                ->first();
            $binding = ConversationBinding::query()
                ->where('organization_id', $organization->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->orderBy('id')
                ->first();
            $channel = $latestTurn instanceof CompanionTurn
                ? (string) $latestTurn->origin_channel
                : ($binding instanceof ConversationBinding ? (string) $binding->channel : 'portal');
            $recipient = $channel === 'telegram'
                ? (($latestTurn instanceof CompanionTurn ? $latestTurn->transport_chat_id : null)
                    ?? ($binding instanceof ConversationBinding ? $binding->external_key : null))
                : null;
            $inbound = $latestTurn instanceof CompanionTurn
                ? ConversationMessage::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($latestTurn->inbound_message_id)
                    ->first(['id', 'metadata'])
                : null;
            $inboundMetadata = $inbound instanceof ConversationMessage ? ($inbound->metadata ?? []) : [];
            $locale = (string) ($inboundMetadata['locale'] ?? $client->language ?? app()->getLocale());
            $copy = CompanionClientMessage::from($locale);
            $notice = $this->recordMessage->handle(
                organizationId: (int) $organization->getKey(),
                client: $client,
                conversation: $conversation,
                channel: $channel,
                direction: ConversationDirection::Outbound,
                authorType: ConversationAuthorType::System,
                body: $copy->aiResumed,
                contextEpoch: (int) $conversation->context_epoch,
                metadata: ['message_type' => 'ai_resumed', 'locale' => $copy->locale, 'transport' => $channel],
            );
            $deliveryId = null;
            if ($channel === 'telegram' && $recipient !== null) {
                $chunks = $this->formatter->chunks($copy->aiResumed);
                foreach ($chunks as $index => $chunk) {
                    $delivery = CompanionDelivery::query()->create([
                        'organization_id' => $organization->getKey(),
                        'turn_id' => null,
                        'conversation_message_id' => $notice->getKey(),
                        'channel' => 'telegram',
                        'recipient_external_id' => $recipient,
                        'chunk_index' => $index,
                        'chunk_count' => count($chunks),
                        'status' => CompanionDeliveryStatus::Pending,
                        'attempt_count' => 0,
                    ]);
                    $deliveryId ??= (int) $delivery->getKey();
                }
            }
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'companion.ai.resumed',
                targetType: Conversation::class,
                targetId: (string) $conversation->getKey(),
                metadata: ['source' => 'staff_action'],
            );

            return $deliveryId;
        });
        if ($deliveryId !== null) {
            DeliverCompanionMessage::dispatch((int) $organization->getKey(), $deliveryId)->afterCommit();
        }
    }
}
