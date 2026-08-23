<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionMessageAttachment;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnMessage;
use App\Modules\ClientCompanion\Infrastructure\Jobs\ProcessCompanionTurn;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class AcceptCompanionMessage
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly GetOrCreateClientCompanionConversation $conversationResolver,
        private readonly RecordCompanionMessage $recordMessage,
    ) {}

    /** @param list<int> $attachmentIds */
    public function handle(
        Client $client,
        string $channel,
        ?string $body,
        ?string $idempotencyKey,
        ?string $originExternalId,
        ?string $transportChatId = null,
        ?string $locale = null,
        array $attachmentIds = [],
        ?string $mediaGroupId = null,
        ?int $sourceOrdinal = null,
        ?string $payloadHash = null,
        ?string $inputFailureCode = null,
    ): CompanionTurn {
        $organizationId = $this->context->id();
        if ((int) $client->organization_id !== $organizationId) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $channel = strtolower(trim($channel));
        if (! in_array($channel, ['portal', 'telegram'], true)) {
            throw new AuthorizationException('The Companion channel is not available.');
        }

        $idempotencyKey = $idempotencyKey === null ? null : trim($idempotencyKey);
        $originExternalId = $originExternalId === null ? null : trim($originExternalId);
        $mediaGroupId = $mediaGroupId === null ? null : trim($mediaGroupId);
        if ($channel === 'portal' && preg_match('/^[A-Za-z0-9._:-]{16,128}$/', (string) $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Не удалось подтвердить отправку сообщения.']);
        }
        if ($channel === 'telegram' && $originExternalId === null) {
            throw ValidationException::withMessages(['message' => 'Не удалось подтвердить сообщение Telegram.']);
        }

        $body = trim((string) $body);
        $attachments = $this->attachments($organizationId, $client, $attachmentIds);
        if ($body === '' && $attachments->isEmpty()) {
            throw ValidationException::withMessages(['body' => 'Добавьте сообщение или изображение.']);
        }
        if (mb_strlen($body) > (int) config('ai.companion.maximum_message_characters', 8000)) {
            throw ValidationException::withMessages(['body' => 'Сообщение слишком длинное.']);
        }
        if ($attachments->isNotEmpty() && $body === '') {
            $body = '[Изображение]';
        }

        $requestHash = $payloadHash !== null && preg_match('/^[a-f0-9]{64}$/', $payloadHash) === 1
            ? $payloadHash
            : hash('sha256', json_encode([
                'client_id' => $client->getKey(),
                'channel' => $channel,
                'body' => $body,
                'idempotency_key' => $idempotencyKey,
                'origin_external_id' => $originExternalId,
                'media_group_id' => $mediaGroupId,
                'attachment_ids' => $attachments->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all(),
            ], JSON_THROW_ON_ERROR));

        $conversation = $this->conversationResolver->handle(
            $client,
            $channel,
            $channel === 'portal' ? 'client:'.$client->getKey() : (string) $transportChatId,
        );

        try {
            return DB::transaction(function () use (
                $organizationId,
                $client,
                $conversation,
                $channel,
                $body,
                $idempotencyKey,
                $originExternalId,
                $transportChatId,
                $locale,
                $attachments,
                $mediaGroupId,
                $sourceOrdinal,
                $requestHash,
                $inputFailureCode,
            ): CompanionTurn {
                $existing = CompanionTurn::query()
                    ->where('organization_id', $organizationId)
                    ->when($idempotencyKey !== null, fn ($query) => $query->where('idempotency_key', $idempotencyKey))
                    ->when($idempotencyKey === null && $originExternalId !== null, fn ($query) => $query
                        ->where('origin_channel', $channel)
                        ->where('origin_external_id', $originExternalId))
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof CompanionTurn) {
                    if ((int) $existing->client_id !== (int) $client->getKey() || $existing->request_hash !== $requestHash) {
                        throw new AuthorizationException('The idempotency key cannot be reused for another message.');
                    }

                    return $existing;
                }

                if ($originExternalId !== null) {
                    $existingMessage = ConversationMessage::query()
                        ->where('organization_id', $organizationId)
                        ->where('channel', $channel)
                        ->where('external_id', $originExternalId)
                        ->lockForUpdate()
                        ->first();
                    if ($existingMessage instanceof ConversationMessage) {
                        $existingTurnMessage = CompanionTurnMessage::query()
                            ->where('organization_id', $organizationId)
                            ->where('conversation_message_id', $existingMessage->getKey())
                            ->first();
                        if (! $existingTurnMessage instanceof CompanionTurnMessage
                            || $existingTurnMessage->request_hash !== $requestHash) {
                            throw new AuthorizationException('The transport message identity cannot be reused for different content.');
                        }

                        return CompanionTurn::query()
                            ->where('organization_id', $organizationId)
                            ->whereKey($existingTurnMessage->turn_id)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }
                }

                $lockedConversation = Conversation::query()
                    ->where('organization_id', $organizationId)
                    ->where('client_id', $client->getKey())
                    ->whereKey($conversation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $pendingCount = CompanionTurn::query()
                    ->where('organization_id', $organizationId)
                    ->where('conversation_id', $lockedConversation->getKey())
                    ->whereIn('status', [CompanionTurnStatus::Pending, CompanionTurnStatus::Processing])
                    ->count();
                if ($pendingCount >= (int) config('ai.companion.maximum_pending_turns', 4)) {
                    throw new TooManyRequestsHttpException(null, 'Companion processing is temporarily busy.');
                }

                $message = $this->recordMessage->handle(
                    organizationId: $organizationId,
                    client: $client,
                    conversation: $lockedConversation,
                    channel: $channel,
                    direction: ConversationDirection::Inbound,
                    authorType: ConversationAuthorType::Client,
                    body: $body,
                    externalMessageId: $originExternalId,
                    contextEpoch: (int) $lockedConversation->context_epoch,
                    metadata: [
                        'chat_type' => $channel === 'telegram' ? 'private' : 'portal',
                        'message_type' => $attachments->isNotEmpty() ? 'image' : 'text',
                        'attachment_count' => $attachments->count(),
                        'media_group_id' => $mediaGroupId,
                        'locale' => $locale,
                        'transport' => $channel,
                    ],
                );

                $maxImages = max(1, (int) config('ai.companion.maximum_images_per_turn', 10));
                $maxTotalBytes = max(1, (int) config('ai.companion.maximum_image_total_bytes', 20_971_520));
                $maxBurstMessages = max(1, (int) config('ai.companion.maximum_burst_messages', 4));
                $maxBurstCharacters = max(1, (int) config('ai.companion.maximum_burst_characters', 12000));
                $burst = $this->findBurst($organizationId, $lockedConversation, $mediaGroupId);
                $burstWouldExceed = $burst instanceof CompanionTurn
                    && ((int) $burst->burst_message_count + 1 > $maxBurstMessages
                        || (int) $burst->burst_text_characters + mb_strlen($body) > $maxBurstCharacters
                        || (int) $burst->input_item_count + $attachments->count() > $maxImages
                        || (int) $burst->input_total_bytes + $attachments->sum('size_bytes') > $maxTotalBytes);

                $canJoinBurst = $burst instanceof CompanionTurn
                    && (! $burstWouldExceed || $mediaGroupId !== null);
                if ($canJoinBurst) {
                    if ($inputFailureCode !== null || $burstWouldExceed) {
                        $burst->update(['input_failure_code' => $inputFailureCode ?? 'input_limit_exceeded']);
                    }

                    $this->addToTurn(
                        organizationId: $organizationId,
                        client: $client,
                        conversation: $lockedConversation,
                        turn: $burst,
                        message: $message,
                        attachments: $attachments,
                        mediaGroupId: $mediaGroupId,
                        sourceOrdinal: $sourceOrdinal,
                        body: $body,
                        requestHash: $requestHash,
                    );

                    return $burst->refresh();
                }

                $status = $lockedConversation->automation_state === ConversationAutomationState::HumanHandoff
                    ? CompanionTurnStatus::Paused
                    : CompanionTurnStatus::Pending;
                $turn = CompanionTurn::query()->create([
                    'organization_id' => $organizationId,
                    'client_id' => $client->getKey(),
                    'conversation_id' => $lockedConversation->getKey(),
                    'sequence' => ((int) CompanionTurn::query()
                        ->where('organization_id', $organizationId)
                        ->where('conversation_id', $lockedConversation->getKey())
                        ->max('sequence')) + 1,
                    'context_epoch' => (int) $lockedConversation->context_epoch,
                    'inbound_message_id' => $message->getKey(),
                    'origin_channel' => $channel,
                    'origin_external_id' => $originExternalId,
                    'transport_chat_id' => $transportChatId,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'status' => $status,
                    'burst_expires_at' => $status === CompanionTurnStatus::Pending && ! $burstWouldExceed
                        ? now()->addMilliseconds(max(250, (int) config('ai.companion.burst_window_milliseconds', 1200)))
                        : null,
                    'burst_message_count' => 0,
                    'burst_text_characters' => 0,
                    'input_modality' => $attachments->isNotEmpty() ? 'image' : 'text',
                    'media_group_id' => $mediaGroupId,
                    'input_item_count' => 0,
                    'input_total_bytes' => 0,
                    'input_failure_code' => $inputFailureCode ?? ($burstWouldExceed ? 'input_limit_exceeded' : null),
                    'accepted_at' => now(),
                ]);
                $this->addToTurn(
                    organizationId: $organizationId,
                    client: $client,
                    conversation: $lockedConversation,
                    turn: $turn,
                    message: $message,
                    attachments: $attachments,
                    mediaGroupId: $mediaGroupId,
                    sourceOrdinal: $sourceOrdinal,
                    body: $body,
                    requestHash: $requestHash,
                );

                if ($status === CompanionTurnStatus::Pending) {
                    ProcessCompanionTurn::dispatch($organizationId, $turn->getKey())->afterCommit();
                }

                return $turn->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            $existing = CompanionTurn::query()
                ->where('organization_id', $organizationId)
                ->when($idempotencyKey !== null, fn ($query) => $query->where('idempotency_key', $idempotencyKey))
                ->when($idempotencyKey === null && $originExternalId !== null, fn ($query) => $query
                    ->where('origin_channel', $channel)
                    ->where('origin_external_id', $originExternalId))
                ->first();
            if ($existing instanceof CompanionTurn && $existing->request_hash === $requestHash) {
                return $existing;
            }

            throw new AuthorizationException('The message was already accepted with different content.');
        }
    }

    /** @param list<int> $attachmentIds */
    /**
     * @param  list<int>  $attachmentIds
     * @return Collection<int, MedicalAttachment>
     */
    private function attachments(int $organizationId, Client $client, array $attachmentIds): Collection
    {
        $attachmentIds = array_values(array_unique(array_map('intval', $attachmentIds)));
        if ($attachmentIds === []) {
            return collect();
        }
        if (count($attachmentIds) > max(1, (int) config('ai.companion.maximum_images_per_turn', 10))) {
            throw ValidationException::withMessages(['images' => 'Отправьте меньше изображений.']);
        }

        $attachments = MedicalAttachment::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('attachment_type', AttachmentType::CompanionImage)
            ->whereIn('id', $attachmentIds)
            ->get()
            ->sortBy(static function (MedicalAttachment $attachment) use ($attachmentIds): int {
                $position = array_search((int) $attachment->getKey(), $attachmentIds, true);

                return $position === false ? PHP_INT_MAX : $position;
            })
            ->values();
        if ($attachments->count() !== count($attachmentIds) || $attachments->contains(fn (MedicalAttachment $attachment): bool => ! $attachment->isAvailable())) {
            throw ValidationException::withMessages(['images' => 'Изображение ещё не готово для обработки.']);
        }
        if ((int) $attachments->sum('size_bytes') > (int) config('ai.companion.maximum_image_total_bytes', 20_971_520)) {
            throw ValidationException::withMessages(['images' => 'Изображения слишком большие. Отправьте меньше или меньшего размера.']);
        }

        return $attachments;
    }

    private function findBurst(int $organizationId, Conversation $conversation, ?string $mediaGroupId): ?CompanionTurn
    {
        return CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversation->getKey())
            ->where('context_epoch', $conversation->context_epoch)
            ->where('status', CompanionTurnStatus::Pending)
            ->where('burst_expires_at', '>', now())
            ->when(
                $mediaGroupId !== null,
                fn ($query) => $query->where('media_group_id', $mediaGroupId),
                fn ($query) => $query->whereNull('media_group_id'),
            )
            ->latest('sequence')
            ->lockForUpdate()
            ->first();
    }

    /** @param Collection<int, MedicalAttachment> $attachments */
    private function addToTurn(
        int $organizationId,
        Client $client,
        Conversation $conversation,
        CompanionTurn $turn,
        ConversationMessage $message,
        Collection $attachments,
        ?string $mediaGroupId,
        ?int $sourceOrdinal,
        string $body,
        string $requestHash,
    ): void {
        $sequence = ((int) $turn->burst_message_count) + 1;
        CompanionTurnMessage::query()->create([
            'organization_id' => $organizationId,
            'turn_id' => $turn->getKey(),
            'conversation_message_id' => $message->getKey(),
            'sequence' => $sequence,
            'request_hash' => $requestHash,
        ]);
        foreach ($attachments as $index => $attachment) {
            CompanionMessageAttachment::query()->create([
                'organization_id' => $organizationId,
                'client_id' => $client->getKey(),
                'conversation_id' => $conversation->getKey(),
                'turn_id' => $turn->getKey(),
                'conversation_message_id' => $message->getKey(),
                'medical_attachment_id' => $attachment->getKey(),
                'media_group_id' => $mediaGroupId,
                'source_ordinal' => $sourceOrdinal,
                'item_index' => $index + 1,
            ]);
        }
        $turn->update([
            'burst_message_count' => $sequence,
            'burst_text_characters' => (int) $turn->burst_text_characters + mb_strlen($body),
            'burst_expires_at' => now()->addMilliseconds(max(250, (int) config('ai.companion.burst_window_milliseconds', 1200))),
            'input_modality' => $attachments->isNotEmpty() ? 'image' : $turn->input_modality,
            'input_item_count' => (int) $turn->input_item_count + $attachments->count(),
            'input_total_bytes' => (int) $turn->input_total_bytes + (int) $attachments->sum('size_bytes'),
        ]);
    }
}
