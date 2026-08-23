<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\Channels\Infrastructure\Telegram\TelegramCompanionFormatter;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionFailureCode;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;

final class InvalidateCompanionAlbum
{
    public function __construct(
        private readonly RecordCompanionMessage $recordMessage,
        private readonly TelegramCompanionFormatter $formatter,
    ) {}

    /** @return list<int> */
    public function handle(
        int $organizationId,
        Client $client,
        Conversation $conversation,
        CompanionTurn $turn,
        string $locale,
    ): array {
        if ($turn->album_recovery_message_id !== null) {
            return [];
        }

        $clientMessage = CompanionClientMessage::from($locale);
        $outbound = $this->recordMessage->handle(
            organizationId: $organizationId,
            client: $client,
            conversation: $conversation,
            channel: $turn->origin_channel,
            direction: ConversationDirection::Outbound,
            authorType: ConversationAuthorType::System,
            body: $clientMessage->albumIncomplete(),
            contextEpoch: $turn->context_epoch,
            metadata: [
                'message_type' => 'album_incomplete',
                'locale' => $clientMessage->locale,
                'transport' => $turn->origin_channel,
            ],
        );

        $deliveryIds = $this->createDeliveries($organizationId, $turn, $outbound, $clientMessage->albumIncomplete());
        $updates = [
            'album_recovery_message_id' => $outbound->getKey(),
            'album_incomplete_at' => $turn->album_incomplete_at ?? now(),
            'input_failure_code' => CompanionFailureCode::MediaGroupIncomplete->value,
        ];
        if (in_array($turn->status, [
            CompanionTurnStatus::Assembling,
            CompanionTurnStatus::Pending,
            CompanionTurnStatus::Processing,
        ], true)) {
            $updates += [
                'status' => CompanionTurnStatus::Cancelled,
                'typing_active' => false,
                'typing_owner_token' => null,
                'typing_chat_id' => null,
                'processing_lease_token' => null,
                'processing_lease_expires_at' => null,
                'completed_at' => now(),
            ];
        }
        $turn->update($updates);

        if ($deliveryIds !== []) {
            DeliverCompanionMessage::dispatch($organizationId, $deliveryIds[0])->afterCommit();
        }

        return $deliveryIds;
    }

    /** @return list<int> */
    private function createDeliveries(
        int $organizationId,
        CompanionTurn $turn,
        ConversationMessage $message,
        string $semanticText,
    ): array {
        if ($turn->origin_channel !== 'telegram' || $turn->transport_chat_id === null) {
            return [];
        }

        $chunks = $this->formatter->chunks($semanticText);
        $ids = [];
        foreach ($chunks as $index => $chunk) {
            $delivery = CompanionDelivery::query()->create([
                'organization_id' => $organizationId,
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
    }
}
