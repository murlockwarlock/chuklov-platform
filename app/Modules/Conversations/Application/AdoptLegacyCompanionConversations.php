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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class AdoptLegacyCompanionConversations
{
    public function __construct(private readonly MedicalEncryptorInterface $encryptor) {}

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
        return DB::transaction(function () use ($conversationId): string {
            $legacy = Conversation::query()->whereKey($conversationId)->lockForUpdate()->first();
            if (! $legacy instanceof Conversation || $legacy->conversation_type !== ConversationType::Channel) {
                return 'already_adopted';
            }

            $legacyChannel = strtolower(trim((string) $legacy->channel));
            $legacyExternalKey = trim((string) $legacy->external_key);
            if (! $this->hasDeterministicOwnership($legacy, $legacyChannel, $legacyExternalKey)) {
                return 'ambiguous';
            }

            $client = Client::query()
                ->where('organization_id', $legacy->organization_id)
                ->whereKey($legacy->client_id)
                ->first();
            if (! $client instanceof Client || (int) $client->organization_id !== (int) $legacy->organization_id) {
                return 'ambiguous';
            }

            $binding = ConversationBinding::query()
                ->where('organization_id', $legacy->organization_id)
                ->where('channel', $legacyChannel)
                ->where('external_key', $legacyExternalKey)
                ->lockForUpdate()
                ->first();
            if ($binding instanceof ConversationBinding && (int) $binding->client_id !== (int) $client->getKey()) {
                return 'ambiguous';
            }

            $targets = Conversation::query()
                ->where('organization_id', $legacy->organization_id)
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->lockForUpdate()
                ->get();
            if ($targets->count() > 1) {
                return 'ambiguous';
            }

            $target = $targets->first();
            if (! $target instanceof Conversation) {
                $canonical = Conversation::query()
                    ->where('organization_id', $legacy->organization_id)
                    ->where('channel', 'companion')
                    ->where('external_key', 'client:'.$client->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($canonical instanceof Conversation && $canonical->getKey() !== $legacy->getKey()) {
                    return 'ambiguous';
                }

                $target = $legacy;
                $target->update([
                    'channel' => 'companion',
                    'external_key' => 'client:'.$client->getKey(),
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
        });
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
