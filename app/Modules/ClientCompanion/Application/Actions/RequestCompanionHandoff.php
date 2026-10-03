<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\Channels\Infrastructure\Telegram\TelegramCompanionFormatter;
use App\Modules\ClientCompanion\Application\Services\CompanionClientMessage;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionSafeAction;
use App\Modules\ClientCompanion\Domain\Enums\RequestCompanionHandoffResult;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnAttempt;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Jobs\ProcessScenarioEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RequestCompanionHandoff
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly EnsureOperationalNotificationDefaults $notificationDefaults,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly RecordCompanionMessage $recordMessage,
        private readonly TelegramCompanionFormatter $formatter,
    ) {}

    public function handle(Client $client, int $messageId): RequestCompanionHandoffResult
    {
        $organizationId = $this->context->id();
        if ((int) $client->organization_id !== $organizationId) {
            throw new AuthorizationException('The Companion action is outside the organization.');
        }

        $this->notificationDefaults->handle($this->context->organization());
        $result = DB::transaction(function () use ($organizationId, $client, $messageId): array {
            $messageReference = ConversationMessage::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->whereKey($messageId)
                ->first();
            if (! $messageReference instanceof ConversationMessage
                || ! $messageReference->conversation()->where('conversation_type', ConversationType::ClientCompanion)->exists()) {
                throw new AuthorizationException('The Companion action is not available.');
            }

            $conversation = Conversation::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->whereKey($messageReference->conversation_id)
                ->lockForUpdate()
                ->firstOrFail();
            $message = ConversationMessage::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->whereKey($messageId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($conversation->automation_state === ConversationAutomationState::HumanHandoff) {
                return ['result' => RequestCompanionHandoffResult::Unavailable, 'deliveryId' => null, 'scenarioEventId' => null];
            }
            $metadata = $message->metadata ?? [];
            $safeActions = array_filter(explode(',', (string) ($metadata['safe_actions'] ?? '')));
            if (! in_array(CompanionSafeAction::RequestHuman->value, $safeActions, true)) {
                throw new AuthorizationException('The Companion action is not available.');
            }

            $turnId = CompanionTurnAttempt::query()
                ->where('organization_id', $organizationId)
                ->where('output_message_id', $message->getKey())
                ->value('turn_id');
            $turn = CompanionTurn::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversation->getKey())
                ->when($turnId !== null, fn ($query) => $query->whereKey($turnId), fn ($query) => $query->where('outbound_message_id', $message->getKey()))
                ->lockForUpdate()
                ->firstOrFail();
            $sameRequest = CompanionEscalation::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversation->getKey())
                ->where('reason', CompanionEscalationReason::HumanRequested)
                ->where('safe_metadata->source_message_id', $messageId)
                ->first();
            if ($sameRequest instanceof CompanionEscalation) {
                return ['result' => RequestCompanionHandoffResult::AlreadyRequested, 'deliveryId' => null, 'scenarioEventId' => null];
            }
            $existing = CompanionEscalation::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversation->getKey())
                ->where('reason', CompanionEscalationReason::HumanRequested)
                ->where('status', CompanionEscalationStatus::Open)
                ->first();
            if ($existing instanceof CompanionEscalation) {
                return ['result' => RequestCompanionHandoffResult::AlreadyRequested, 'deliveryId' => null, 'scenarioEventId' => null];
            }

            $escalation = CompanionEscalation::query()->create([
                'organization_id' => $organizationId,
                'client_id' => $client->getKey(),
                'conversation_id' => $conversation->getKey(),
                'turn_id' => $turn->getKey(),
                'ai_run_id' => $turn->ai_run_id,
                'reason' => CompanionEscalationReason::HumanRequested,
                'status' => CompanionEscalationStatus::Open,
                'safe_metadata' => ['source' => 'client_action', 'source_message_id' => $messageId],
                'opened_at' => now(),
            ]);
            $locale = (string) ($metadata['locale'] ?? $client->language ?? app()->getLocale());
            $copy = CompanionClientMessage::from($locale);
            $acknowledgement = $this->recordMessage->handle(
                organizationId: $organizationId,
                client: $client,
                conversation: $conversation,
                channel: (string) $message->channel,
                direction: ConversationDirection::Outbound,
                authorType: ConversationAuthorType::Ai,
                body: $copy->specialistNotified,
                contextEpoch: $conversation->context_epoch,
                metadata: ['message_type' => 'specialist_notified', 'locale' => $copy->locale, 'transport' => $message->channel],
            );
            $deliveryId = null;
            if ($message->channel === 'telegram' && $turn->transport_chat_id !== null) {
                $chunks = $this->formatter->chunks($copy->specialistNotified);
                foreach ($chunks as $index => $chunk) {
                    $delivery = CompanionDelivery::query()->firstOrCreate([
                        'organization_id' => $organizationId,
                        'conversation_message_id' => $acknowledgement->getKey(),
                        'chunk_index' => $index,
                    ], [
                        'turn_id' => null,
                        'channel' => 'telegram',
                        'recipient_external_id' => $turn->transport_chat_id,
                        'chunk_count' => count($chunks),
                        'status' => CompanionDeliveryStatus::Pending,
                        'attempt_count' => 0,
                    ]);
                    $deliveryId ??= (int) $delivery->getKey();
                }
            }
            $scenarioEvent = $this->scenarioEvents->companionEscalationRecorded($escalation, CarbonImmutable::now());

            return [
                'result' => RequestCompanionHandoffResult::Created,
                'deliveryId' => $deliveryId,
                'scenarioEventId' => (int) $scenarioEvent->getKey(),
            ];
        });

        if ($result['deliveryId'] !== null) {
            DeliverCompanionMessage::dispatch($organizationId, $result['deliveryId'])->afterCommit();
        }
        if ($result['scenarioEventId'] !== null) {
            ProcessScenarioEvent::dispatch((int) $result['scenarioEventId'])->afterCommit();
        }

        return $result['result'];
    }
}
