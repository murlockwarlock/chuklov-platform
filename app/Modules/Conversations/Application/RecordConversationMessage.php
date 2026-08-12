<?php

namespace App\Modules\Conversations\Application;

use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordConversationMessage
{
    /** @var list<string> */
    private const METADATA_KEYS = [
        'provider_message_id',
        'chat_type',
        'message_type',
        'locale',
    ];

    public function __construct(private readonly OrganizationContext $context) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Client $client,
        string $channel,
        string $conversationKey,
        ConversationDirection $direction,
        ConversationAuthorType $authorType,
        ?string $body = null,
        ?string $externalMessageId = null,
        array $metadata = [],
    ): ConversationMessage {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $channel = strtolower(trim($channel));
        $conversationKey = trim($conversationKey);
        $externalMessageId = $externalMessageId === null ? null : trim($externalMessageId);

        if ($channel === '' || mb_strlen($channel) > 32 || preg_match('/^[a-z0-9._-]+$/', $channel) !== 1) {
            throw new InvalidArgumentException('The conversation channel is invalid.');
        }

        if ($conversationKey === '' || mb_strlen($conversationKey) > 191) {
            throw new InvalidArgumentException('The conversation key is invalid.');
        }

        if ($body !== null && mb_strlen($body) > 10000) {
            throw new InvalidArgumentException('The conversation message is too long.');
        }

        if ($externalMessageId !== null && ($externalMessageId === '' || mb_strlen($externalMessageId) > 191)) {
            throw new InvalidArgumentException('The external message identifier is invalid.');
        }

        try {
            return $this->persist(
                $organization->getKey(),
                $client,
                $channel,
                $conversationKey,
                $direction,
                $authorType,
                $body,
                $externalMessageId,
                $this->normalizeMetadata($metadata),
            );
        } catch (UniqueConstraintViolationException) {
            return $this->persist(
                $organization->getKey(),
                $client,
                $channel,
                $conversationKey,
                $direction,
                $authorType,
                $body,
                $externalMessageId,
                $this->normalizeMetadata($metadata),
            );
        }
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function persist(
        int $organizationId,
        Client $client,
        string $channel,
        string $conversationKey,
        ConversationDirection $direction,
        ConversationAuthorType $authorType,
        ?string $body,
        ?string $externalMessageId,
        array $metadata,
    ): ConversationMessage {
        return DB::transaction(function () use (
            $organizationId,
            $client,
            $channel,
            $conversationKey,
            $direction,
            $authorType,
            $body,
            $externalMessageId,
            $metadata,
        ): ConversationMessage {
            $conversation = Conversation::query()
                ->where('organization_id', $organizationId)
                ->where('channel', $channel)
                ->where('external_key', $conversationKey)
                ->lockForUpdate()
                ->first();

            if ($conversation instanceof Conversation && (int) $conversation->client_id !== $client->getKey()) {
                throw new AuthorizationException('The conversation belongs to another client.');
            }

            if (! $conversation instanceof Conversation) {
                $conversation = new Conversation;
                $conversation->forceFill([
                    'organization_id' => $organizationId,
                    'client_id' => $client->getKey(),
                    'channel' => $channel,
                    'external_key' => $conversationKey,
                    'started_at' => now(),
                ]);
                $conversation->save();
            }

            if ($externalMessageId !== null) {
                $existing = ConversationMessage::query()
                    ->where('organization_id', $organizationId)
                    ->where('channel', $channel)
                    ->where('external_id', $externalMessageId)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof ConversationMessage) {
                    if ((int) $existing->client_id !== $client->getKey()) {
                        throw new AuthorizationException('The message belongs to another client.');
                    }

                    return $existing;
                }
            }

            $message = new ConversationMessage;
            $message->forceFill([
                'organization_id' => $organizationId,
                'conversation_id' => $conversation->getKey(),
                'client_id' => $client->getKey(),
                'channel' => $channel,
                'direction' => $direction,
                'author_type' => $authorType,
                'external_id' => $externalMessageId,
                'body' => $body,
                'metadata' => $metadata,
                'occurred_at' => now(),
            ]);
            $message->save();

            $conversation->forceFill(['last_message_at' => $message->occurred_at]);
            $conversation->save();

            return $message->refresh();
        });
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
