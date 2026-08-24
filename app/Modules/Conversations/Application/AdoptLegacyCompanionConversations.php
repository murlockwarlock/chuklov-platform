<?php

namespace App\Modules\Conversations\Application;

use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationBinding;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class AdoptLegacyCompanionConversations
{
    public function __construct(
        private readonly MedicalEncryptorInterface $encryptor,
        private readonly ConversationOwnershipLock $ownershipLock,
    ) {}

    /** @return array{adopted: int, skipped: int, ambiguous: int, already_adopted: int} */
    public function handle(): array
    {
        $stats = $this->newStats();

        Conversation::query()
            ->where('conversation_type', ConversationType::Channel)
            ->whereIn('channel', ['portal', 'telegram'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $conversations) use (&$stats): void {
                foreach ($conversations as $conversation) {
                    $result = $this->adopt((int) $conversation->getKey());
                    $stats[$result]++;
                }
            });

        return $stats;
    }

    /** @return 'adopted'|'skipped'|'ambiguous'|'already_adopted' */
    private function adopt(int $conversationId): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(
                    fn (): string => $this->adoptWithinTransaction($conversationId),
                    attempts: 3,
                );
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('The legacy Companion conversation could not be adopted.');
    }

    /** @return 'adopted'|'skipped'|'ambiguous'|'already_adopted' */
    private function adoptWithinTransaction(int $conversationId): string
    {
        $snapshot = Conversation::query()->whereKey($conversationId)->first();
        if (! $snapshot instanceof Conversation || $snapshot->conversation_type !== ConversationType::Channel) {
            return 'already_adopted';
        }

        $organizationId = (int) $snapshot->organization_id;
        $clientId = (int) $snapshot->client_id;
        $legacyChannel = strtolower(trim((string) $snapshot->channel));
        $legacyExternalKey = trim((string) $snapshot->external_key);
        $snapshotChannel = $legacyChannel;
        $snapshotExternalKey = $legacyExternalKey;
        if (! $this->hasDeterministicOwnership($snapshot, $legacyChannel, $legacyExternalKey)) {
            return 'ambiguous';
        }

        $this->ownershipLock->forClient($organizationId, $clientId);
        $this->ownershipLock->forBinding($organizationId, $legacyChannel, $legacyExternalKey);

        $binding = ConversationBinding::query()
            ->where('organization_id', $organizationId)
            ->where('channel', $legacyChannel)
            ->where('external_key', $legacyExternalKey)
            ->lockForUpdate()
            ->first();

        $candidateIds = [$conversationId];
        if ($binding instanceof ConversationBinding) {
            $candidateIds[] = (int) $binding->conversation_id;
        }
        $candidateIds = array_merge(
            $candidateIds,
            Conversation::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $clientId)
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );
        $canonical = Conversation::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->where('channel', 'companion')
            ->where('external_key', 'client:'.$clientId)
            ->first();
        if ($canonical instanceof Conversation) {
            $candidateIds[] = (int) $canonical->getKey();
        }

        $lockedConversations = $this->lockConversations($organizationId, $candidateIds);
        $legacy = $lockedConversations[$conversationId] ?? null;
        if (! $legacy instanceof Conversation || $legacy->conversation_type !== ConversationType::Channel) {
            return 'already_adopted';
        }

        $legacyChannel = strtolower(trim((string) $legacy->channel));
        $legacyExternalKey = trim((string) $legacy->external_key);
        if (! $this->hasDeterministicOwnership($legacy, $legacyChannel, $legacyExternalKey)
            || (int) $legacy->organization_id !== $organizationId
            || (int) $legacy->client_id !== $clientId
            || $legacyChannel !== $snapshotChannel
            || $legacyExternalKey !== $snapshotExternalKey) {
            return 'ambiguous';
        }

        $client = Client::query()
            ->where('organization_id', $organizationId)
            ->whereKey($clientId)
            ->first();
        if (! $client instanceof Client || (int) $client->organization_id !== $organizationId) {
            return 'ambiguous';
        }

        if ($binding instanceof ConversationBinding && (int) $binding->client_id !== $clientId) {
            return 'ambiguous';
        }

        $targets = collect($lockedConversations)
            ->filter(static fn (Conversation $conversation): bool => $conversation->conversation_type === ConversationType::ClientCompanion
                && (int) $conversation->client_id === $clientId)
            ->values();
        if ($targets->count() > 1) {
            return 'ambiguous';
        }

        $target = $targets->first();
        $canonical = collect($lockedConversations)->first(
            static fn (Conversation $conversation): bool => (int) $conversation->client_id === $clientId
                && $conversation->channel === 'companion'
                && $conversation->external_key === 'client:'.$clientId,
        );

        if (! $target instanceof Conversation) {
            if ($binding instanceof ConversationBinding
                && (int) $binding->conversation_id !== (int) $legacy->getKey()) {
                return 'ambiguous';
            }
            if ($canonical instanceof Conversation && (int) $canonical->getKey() !== (int) $legacy->getKey()) {
                return 'ambiguous';
            }

            $target = $legacy;
            $target->update([
                'channel' => 'companion',
                'external_key' => 'client:'.$clientId,
                'conversation_type' => ConversationType::ClientCompanion,
                'automation_state' => ConversationAutomationState::AiActive,
                'context_epoch' => max(1, (int) $target->context_epoch),
            ]);
        } elseif ($binding instanceof ConversationBinding
            && ! in_array((int) $binding->conversation_id, [(int) $legacy->getKey(), (int) $target->getKey()], true)) {
            return 'ambiguous';
        }

        if ((int) $target->getKey() === (int) $legacy->getKey()) {
            $this->encryptCompanionMessages($target);
        } elseif (! $this->moveMessages($legacy, $target)) {
            return 'ambiguous';
        }

        if (! $this->adoptBinding($binding, $target, $legacy, $client, $legacyChannel, $legacyExternalKey)) {
            return 'ambiguous';
        }

        $this->refreshChronology($target);

        return 'adopted';
    }

    /**
     * @param  array<int, int>  $conversationIds
     * @return array<int, Conversation>
     */
    private function lockConversations(int $organizationId, array $conversationIds): array
    {
        $conversationIds = array_values(array_unique(array_map('intval', $conversationIds)));
        sort($conversationIds, SORT_NUMERIC);
        /** @var array<int, Conversation> $locked */
        $locked = [];
        foreach ($conversationIds as $conversationId) {
            $conversation = Conversation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->first();
            if ($conversation instanceof Conversation) {
                $locked[(int) $conversation->getKey()] = $conversation;
            }
        }

        return $locked;
    }

    private function hasDeterministicOwnership(Conversation $legacy, string $channel, string $externalKey): bool
    {
        return (int) $legacy->organization_id > 0
            && (int) $legacy->client_id > 0
            && in_array($channel, ['portal', 'telegram'], true)
            && preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $externalKey) === 1;
    }

    /** @return array{adopted: int, skipped: int, ambiguous: int, already_adopted: int} */
    private function newStats(): array
    {
        return ['adopted' => 0, 'skipped' => 0, 'ambiguous' => 0, 'already_adopted' => 0];
    }

    private function adoptBinding(
        ?ConversationBinding $binding,
        Conversation $target,
        Conversation $legacy,
        Client $client,
        string $channel,
        string $externalKey,
    ): bool {
        if ($binding instanceof ConversationBinding) {
            if ((int) $binding->client_id !== (int) $client->getKey()
                || ! in_array((int) $binding->conversation_id, [(int) $target->getKey(), (int) $legacy->getKey()], true)) {
                return false;
            }

            if ((int) $binding->conversation_id === (int) $legacy->getKey()
                && (int) $legacy->getKey() !== (int) $target->getKey()) {
                $binding->update(['conversation_id' => $target->getKey()]);
            }

            return true;
        }

        ConversationBinding::query()->create([
            'organization_id' => $target->organization_id,
            'conversation_id' => $target->getKey(),
            'client_id' => $client->getKey(),
            'channel' => $channel,
            'external_key' => $externalKey,
        ]);

        return true;
    }

    private function moveMessages(Conversation $legacy, Conversation $target): bool
    {
        $valid = true;
        ConversationMessage::query()
            ->where('organization_id', $legacy->organization_id)
            ->where('conversation_id', $legacy->getKey())
            ->orderBy('id')
            ->chunkById(200, function (Collection $messages) use ($legacy, &$valid): void {
                foreach ($messages as $message) {
                    if ((int) $message->organization_id !== (int) $legacy->organization_id
                        || (int) $message->client_id !== (int) $legacy->client_id
                        || $message->channel !== $legacy->channel) {
                        $valid = false;

                        return;
                    }
                }
            });
        if (! $valid) {
            return false;
        }

        ConversationMessage::query()
            ->where('organization_id', $legacy->organization_id)
            ->where('conversation_id', $legacy->getKey())
            ->orderBy('id')
            ->chunkById(200, function (Collection $messages) use ($legacy, $target): void {
                foreach ($messages as $message) {
                    $updates = [
                        'conversation_id' => $target->getKey(),
                        'companion_context_epoch' => (int) $target->context_epoch,
                    ];
                    if ($message->body !== null && $message->encrypted_body === null) {
                        $keyVersion = (int) Config::get('medical.key_version', 1);
                        $updates['encrypted_body'] = $this->encryptor->encryptField(
                            (int) $legacy->organization_id,
                            (string) $message->body,
                            $keyVersion,
                        );
                        $updates['encryption_key_version'] = $keyVersion;
                        $updates['body'] = null;
                    }
                    $message->forceFill($updates)->save();
                }
            });

        return true;
    }

    private function encryptCompanionMessages(Conversation $target): void
    {
        ConversationMessage::query()
            ->where('organization_id', $target->organization_id)
            ->where('conversation_id', $target->getKey())
            ->whereNotNull('body')
            ->whereNull('encrypted_body')
            ->orderBy('id')
            ->chunkById(200, function (Collection $messages) use ($target): void {
                foreach ($messages as $message) {
                    $keyVersion = (int) Config::get('medical.key_version', 1);
                    $message->forceFill([
                        'body' => null,
                        'encrypted_body' => $this->encryptor->encryptField(
                            (int) $target->organization_id,
                            (string) $message->body,
                            $keyVersion,
                        ),
                        'encryption_key_version' => $keyVersion,
                        'companion_context_epoch' => (int) $target->context_epoch,
                    ])->save();
                }
            });
    }

    private function refreshChronology(Conversation $target): void
    {
        $first = ConversationMessage::query()
            ->where('organization_id', $target->organization_id)
            ->where('conversation_id', $target->getKey())
            ->min('occurred_at');
        $last = ConversationMessage::query()
            ->where('organization_id', $target->organization_id)
            ->where('conversation_id', $target->getKey())
            ->max('occurred_at');
        $updates = [];
        if (is_string($first)) {
            $startedAt = $target->started_at;
            if (! $startedAt instanceof CarbonInterface || $startedAt->gt($first)) {
                $updates['started_at'] = $first;
            }
        }
        if (is_string($last)) {
            $updates['last_message_at'] = $last;
        }
        if ($updates !== []) {
            $target->update($updates);
        }
    }
}
