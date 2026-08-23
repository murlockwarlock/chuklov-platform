<?php

namespace App\Modules\ClientCompanion\Infrastructure\Jobs;

use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class CompanionTypingHeartbeatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $turnId,
        public readonly string $ownerToken,
        public readonly int $heartbeatSequence,
    ) {
        $this->onQueue('telegram-typing');
    }

    public function handle(MessagingChannel $channel): void
    {
        $state = DB::transaction(function (): ?array {
            $turn = CompanionTurn::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($this->turnId)
                ->lockForUpdate()
                ->first();

            if ($turn === null
                || $turn->status !== CompanionTurnStatus::Processing
                || ! $turn->typing_active
                || $turn->typing_owner_token !== $this->ownerToken
                || $turn->typing_heartbeat_sequence !== $this->heartbeatSequence
                || $turn->leaseIsExpired()) {
                return null;
            }

            $nextSequence = $this->heartbeatSequence + 1;
            $turn->update(['typing_heartbeat_sequence' => $nextSequence]);

            return [
                'chat_id' => $turn->typing_chat_id,
                'next_sequence' => $nextSequence,
            ];
        });

        if ($state === null || ! is_string($state['chat_id'] ?? null) || $state['chat_id'] === '') {
            return;
        }

        try {
            $channel->sendTyping($state['chat_id']);
        } catch (\Throwable) {
        }

        $stillActive = CompanionTurn::query()
            ->where('organization_id', $this->organizationId)
            ->whereKey($this->turnId)
            ->where('status', CompanionTurnStatus::Processing)
            ->where('typing_active', true)
            ->where('typing_owner_token', $this->ownerToken)
            ->where('typing_heartbeat_sequence', $state['next_sequence'])
            ->exists();

        if ($stillActive) {
            self::dispatch(
                $this->organizationId,
                $this->turnId,
                $this->ownerToken,
                $state['next_sequence'],
            )->delay(now()->addSeconds(max(3, (int) config('ai.companion.typing_heartbeat_seconds', 4))));
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['companion-turn:'.$this->turnId, 'organization:'.$this->organizationId];
    }
}
