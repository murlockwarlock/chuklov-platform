<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Models\User;
use App\Modules\Channels\Infrastructure\Telegram\TelegramCompanionFormatter;
use App\Modules\ClientCompanion\Application\Services\CompanionClientMessage;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnAttemptStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnAttempt;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationBinding;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class TakeOverCompanionConversation
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
        private readonly GetOrCreateClientCompanionConversation $conversationResolver,
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

        $conversationExists = Conversation::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('conversation_type', ConversationType::ClientCompanion)
            ->exists();
        if (! $conversationExists) {
            $telegramIdentity = ClientChannelIdentity::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('channel', 'telegram')
                ->where('verification_status', ChannelIdentityStatus::Verified)
                ->orderBy('id')
                ->first();
            $this->conversationResolver->handle(
                $client,
                $telegramIdentity instanceof ClientChannelIdentity ? 'telegram' : 'portal',
                $telegramIdentity instanceof ClientChannelIdentity
                    ? (string) $telegramIdentity->external_id
                    : 'client:'.$client->getKey(),
            );
        }

        $deliveryIds = DB::transaction(function () use ($organization, $actor, $client): array {
            $conversation = Conversation::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->lockForUpdate()
                ->firstOrFail();
            if ($conversation->automation_state === ConversationAutomationState::HumanHandoff) {
                if ($conversation->last_human_takeover_at !== null) {
                    return [];
                }

                $conversation->update(['last_human_takeover_at' => now()]);
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'companion.handoff.taken_over',
                    targetType: Conversation::class,
                    targetId: (string) $conversation->getKey(),
                    metadata: ['source' => 'staff_action', 'legacy_state_confirmed' => true],
                );
            } else {
                $turns = CompanionTurn::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('conversation_id', $conversation->getKey())
                    ->whereIn('status', [
                        CompanionTurnStatus::Assembling,
                        CompanionTurnStatus::Pending,
                        CompanionTurnStatus::Processing,
                    ])
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get();
                foreach ($turns as $turn) {
                    $turn->update([
                        'status' => CompanionTurnStatus::Paused,
                        'typing_active' => false,
                        'typing_owner_token' => null,
                        'typing_chat_id' => null,
                        'processing_lease_token' => null,
                        'processing_lease_expires_at' => null,
                        'completed_at' => null,
                    ]);
                }
                CompanionTurnAttempt::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereIn('turn_id', $turns->modelKeys())
                    ->whereIn('status', [CompanionTurnAttemptStatus::Pending, CompanionTurnAttemptStatus::Processing])
                    ->update([
                        'status' => CompanionTurnAttemptStatus::Cancelled,
                        'completed_at' => now(),
                    ]);
                $conversation->update([
                    'automation_state' => ConversationAutomationState::HumanHandoff,
                    'last_human_takeover_at' => now(),
                ]);
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'companion.handoff.taken_over',
                    targetType: Conversation::class,
                    targetId: (string) $conversation->getKey(),
                    metadata: ['source' => 'staff_action'],
                );
            }

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
                ? $latestTurn->origin_channel
                : ($binding instanceof ConversationBinding ? $binding->channel : 'portal');
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
                body: $copy->humanTakeover,
                contextEpoch: (int) $conversation->context_epoch,
                metadata: ['message_type' => 'human_takeover', 'locale' => $copy->locale, 'transport' => $channel],
            );

            if ($channel !== 'telegram' || $recipient === null) {
                return [];
            }

            $deliveryIds = [];
            $chunks = $this->formatter->chunks($copy->humanTakeover);
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
                $deliveryIds[] = (int) $delivery->getKey();
            }

            return $deliveryIds;
        });
        foreach ($deliveryIds as $deliveryId) {
            DeliverCompanionMessage::dispatch((int) $organization->getKey(), $deliveryId)->afterCommit();
        }
    }
}
