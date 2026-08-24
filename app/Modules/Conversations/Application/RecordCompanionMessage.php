<?php

namespace App\Modules\Conversations\Application;

use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecordCompanionMessage
{
    /** @var list<string> */
    private const METADATA_KEYS = [
        'provider_message_id',
        'chat_type',
        'message_type',
        'locale',
        'transport',
        'safe_actions',
        'media_group_id',
        'source_ordinal',
        'ingest_state',
    ];

    public function __construct(private readonly MedicalEncryptorInterface $encryptor) {}

    /** @param array<string, mixed> $metadata */
    public function handle(
        int $organizationId,
        Client $client,
        Conversation $conversation,
        string $channel,
        ConversationDirection $direction,
        ConversationAuthorType $authorType,
        string $body,
        ?string $externalMessageId = null,
        ?int $authorUserId = null,
        ?int $contextEpoch = null,
        array $metadata = [],
        ?\DateTimeInterface $occurredAt = null,
    ): ConversationMessage {
        if ((int) $client->organization_id !== $organizationId
            || (int) $conversation->organization_id !== $organizationId
            || (int) $conversation->client_id !== (int) $client->getKey()
            || $conversation->conversation_type !== ConversationType::ClientCompanion) {
            throw new AuthorizationException('The Companion conversation is outside the requested organization or client.');
        }

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 50000) {
            throw new InvalidArgumentException('The Companion message is invalid.');
        }

        $channel = strtolower(trim($channel));
        $externalMessageId = $externalMessageId === null ? null : trim($externalMessageId);
        if (preg_match('/^[a-z0-9._-]{1,32}$/', $channel) !== 1
            || ($externalMessageId !== null && ($externalMessageId === '' || mb_strlen($externalMessageId) > 191))) {
            throw new InvalidArgumentException('The Companion message transport is invalid.');
        }

        $normalizedMetadata = $this->normalizeMetadata($metadata);
        $keyVersion = (int) Config::get('medical.key_version', 1);

        try {
            return DB::transaction(function () use (
                $organizationId,
                $client,
                $conversation,
                $channel,
                $direction,
                $authorType,
                $body,
                $externalMessageId,
                $authorUserId,
                $contextEpoch,
                $normalizedMetadata,
                $keyVersion,
                $occurredAt,
            ): ConversationMessage {
                $lockedConversation = Conversation::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($conversation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($externalMessageId !== null) {
                    $existing = ConversationMessage::query()
                        ->where('organization_id', $organizationId)
                        ->where('channel', $channel)
                        ->where('external_id', $externalMessageId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing instanceof ConversationMessage) {
                        if ((int) $existing->client_id !== (int) $client->getKey()
                            || (int) $existing->conversation_id !== (int) $lockedConversation->getKey()) {
                            throw new AuthorizationException('The Companion message belongs to another conversation.');
                        }

                        return $existing;
                    }
                }

                $message = new ConversationMessage;
                $message->forceFill([
                    'organization_id' => $organizationId,
                    'conversation_id' => $lockedConversation->getKey(),
                    'client_id' => $client->getKey(),
                    'channel' => $channel,
                    'direction' => $direction,
                    'author_type' => $authorType,
                    'author_user_id' => $authorUserId,
                    'external_id' => $externalMessageId,
                    'body' => null,
                    'encrypted_body' => $this->encryptor->encryptField($organizationId, $body, $keyVersion),
                    'encryption_key_version' => $keyVersion,
                    'companion_context_epoch' => $contextEpoch ?? (int) $lockedConversation->context_epoch,
                    'metadata' => $normalizedMetadata,
                    'occurred_at' => $occurredAt ?? now(),
                ]);
                $message->save();

                $lockedConversation->update(['last_message_at' => $message->occurred_at]);

                return $message->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return $this->handle(
                organizationId: $organizationId,
                client: $client,
                conversation: $conversation,
                channel: $channel,
                direction: $direction,
                authorType: $authorType,
                body: $body,
                externalMessageId: $externalMessageId,
                authorUserId: $authorUserId,
                contextEpoch: $contextEpoch,
                metadata: $metadata,
                occurredAt: $occurredAt,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, scalar|null>
     */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (! in_array($key, self::METADATA_KEYS, true) || (! is_scalar($value) && $value !== null)) {
                continue;
            }
            $normalized[$key] = is_string($value) ? Str::limit($value, 128, '…') : $value;
        }

        return $normalized;
    }
}
