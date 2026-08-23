<?php

namespace App\Modules\Conversations\Application;

use Illuminate\Support\Facades\DB;

final class ConversationOwnershipLock
{
    public function forClient(int $organizationId, int $clientId): void
    {
        $this->lock('client', $organizationId.':'.$clientId);
    }

    public function forBinding(int $organizationId, string $channel, string $externalKey): void
    {
        $this->lock('binding', $organizationId.':'.$channel.':'.$externalKey);
    }

    private function lock(string $namespace, string $identity): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $digest = hash('sha256', $namespace."\0".$identity, true);
        $parts = unpack('N2', substr($digest, 0, 8));
        if (! is_array($parts) || ! isset($parts[1], $parts[2])) {
            throw new \RuntimeException('Unable to derive the conversation ownership lock key.');
        }

        DB::select('select pg_advisory_xact_lock(?::integer, ?::integer)', [
            $this->signedInteger((int) $parts[1]),
            $this->signedInteger((int) $parts[2]),
        ]);
    }

    private function signedInteger(int $value): int
    {
        return $value > 2_147_483_647 ? $value - 4_294_967_296 : $value;
    }
}
