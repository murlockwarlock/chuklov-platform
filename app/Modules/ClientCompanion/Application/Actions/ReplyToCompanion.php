<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Models\User;
use App\Modules\Channels\Infrastructure\Telegram\TelegramCompanionFormatter;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ReplyToCompanion
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordCompanionMessage $recordMessage,
        private readonly TelegramCompanionFormatter $formatter,
    ) {}

    public function handle(User $actor, Client $client, string $body): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageCompanionHandoff);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The Companion conversation is outside the organization.');
        }
        $rateKey = 'companion-staff-reply:'.$organization->getKey().':'.$client->getKey();
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            throw new TooManyRequestsHttpException(null, 'Companion replies are temporarily rate limited.');
        }
        RateLimiter::hit($rateKey, 60);

        $recordMessage = $this->recordMessage;
        $formatter = $this->formatter;
        $deliveryIds = DB::transaction(function () use ($organization, $actor, $client, $body, $recordMessage, $formatter): array {
            $conversation = Conversation::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->lockForUpdate()
                ->firstOrFail();
            if ($conversation->automation_state !== ConversationAutomationState::HumanHandoff) {
                throw new AuthorizationException('Staff replies are available during an active handoff.');
            }

            $turn = CompanionTurn::query()
                ->where('organization_id', $organization->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->latest('sequence')
                ->first();
            $channel = $turn === null ? 'portal' : ($turn->origin_channel ?? 'portal');
            $message = $recordMessage->handle(
                organizationId: $organization->getKey(),
                client: $client,
                conversation: $conversation,
                channel: $channel,
                direction: ConversationDirection::Outbound,
                authorType: ConversationAuthorType::Staff,
                body: $body,
                authorUserId: $actor->getKey(),
                contextEpoch: $conversation->context_epoch,
                metadata: ['message_type' => 'staff_reply', 'locale' => $client->language ?? 'en', 'transport' => $channel],
            );

            if ($channel !== 'telegram' || $turn?->transport_chat_id === null) {
                return [];
            }

            $chunks = $formatter->chunks($body);
            $ids = [];
            foreach ($chunks as $index => $chunk) {
                $delivery = CompanionDelivery::query()->create([
                    'organization_id' => $organization->getKey(),
                    'turn_id' => null,
                    'conversation_message_id' => $message->getKey(),
                    'channel' => 'telegram',
                    'recipient_external_id' => $turn->transport_chat_id,
                    'chunk_index' => $index,
                    'chunk_count' => count($chunks),
                    'status' => CompanionDeliveryStatus::Pending,
                    'attempt_count' => 0,
                ]);
                $ids[] = (int) $delivery->getKey();
            }

            return $ids;
        });

        foreach ($deliveryIds as $deliveryId) {
            DeliverCompanionMessage::dispatch(
                $organization->getKey(),
                $deliveryId,
            )->afterCommit();
        }
    }
}
