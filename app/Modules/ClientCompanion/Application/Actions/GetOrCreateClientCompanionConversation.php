<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationBinding;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GetOrCreateClientCompanionConversation
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Client $client, string $channel, string $externalKey): Conversation
    {
        $organizationId = $this->context->id();
        if ((int) $client->organization_id !== $organizationId) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $channel = strtolower(trim($channel));
        $externalKey = trim($externalKey);
        if (preg_match('/^[a-z0-9._-]{1,32}$/', $channel) !== 1 || $externalKey === '' || mb_strlen($externalKey) > 191) {
            throw new InvalidArgumentException('The Companion transport binding is invalid.');
        }

        try {
            return DB::transaction(function () use ($organizationId, $client, $channel, $externalKey): Conversation {
                $binding = ConversationBinding::query()
                    ->where('organization_id', $organizationId)
                    ->where('channel', $channel)
                    ->where('external_key', $externalKey)
                    ->lockForUpdate()
                    ->first();

                if ($binding instanceof ConversationBinding) {
                    if ((int) $binding->client_id !== (int) $client->getKey()) {
                        throw new AuthorizationException('The Companion binding belongs to another client.');
                    }

                    return Conversation::query()
                        ->where('organization_id', $organizationId)
                        ->whereKey($binding->conversation_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $conversation = Conversation::query()
                    ->where('organization_id', $organizationId)
                    ->where('client_id', $client->getKey())
                    ->where('conversation_type', ConversationType::ClientCompanion)
                    ->lockForUpdate()
                    ->first();

                if (! $conversation instanceof Conversation) {
                    $conversation = new Conversation;
                    $conversation->forceFill([
                        'organization_id' => $organizationId,
                        'client_id' => $client->getKey(),
                        'channel' => 'companion',
                        'external_key' => 'client:'.$client->getKey(),
                        'conversation_type' => ConversationType::ClientCompanion,
                        'automation_state' => ConversationAutomationState::AiActive,
                        'context_epoch' => 1,
                        'started_at' => now(),
                    ]);
                    $conversation->save();
                }

                ConversationBinding::query()->create([
                    'organization_id' => $organizationId,
                    'conversation_id' => $conversation->getKey(),
                    'client_id' => $client->getKey(),
                    'channel' => $channel,
                    'external_key' => $externalKey,
                ]);

                return $conversation->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return $this->handle($client, $channel, $externalKey);
        }
    }
}
