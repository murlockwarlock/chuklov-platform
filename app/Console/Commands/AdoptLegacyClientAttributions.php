<?php

namespace App\Console\Commands;

use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('clients:adopt-attribution {--limit=100 : Maximum number of clients to adopt in this invocation}')]
#[Description('Adopt bounded legacy Client attribution fields into the M11A first-touch table.')]
class AdoptLegacyClientAttributions extends Command
{
    public function handle(RecordAuditEvent $audit): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $clients = Client::query()
            ->whereNotNull('lead_source')
            ->where('lead_source', '<>', '')
            ->whereDoesntHave('attribution')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $adopted = 0;

        foreach ($clients as $client) {
            $created = DB::transaction(function () use ($client, $audit): bool {
                $lockedClient = Client::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();

                if (ClientAttribution::query()
                    ->where('organization_id', $lockedClient->organization_id)
                    ->where('client_id', $lockedClient->getKey())
                    ->exists()) {
                    return false;
                }

                $source = Str::limit(trim((string) $lockedClient->lead_source), 120, '');
                if ($source === '') {
                    return false;
                }

                $attribution = new ClientAttribution;
                $attribution->forceFill([
                    'organization_id' => $lockedClient->organization_id,
                    'client_id' => $lockedClient->getKey(),
                    'source_type' => 'legacy',
                    'source' => $source,
                    'capture_channel' => 'legacy_adoption',
                    'capture_context' => 'clients.lead_source',
                    'captured_at' => $lockedClient->created_at ?? now(),
                    'accepted_at' => now(),
                ]);
                $attribution->save();
                $audit->handle(
                    organization: $lockedClient->organization,
                    actor: null,
                    action: 'attribution.legacy.adopted',
                    targetType: ClientAttribution::class,
                    targetId: (string) $attribution->getKey(),
                    metadata: ['source_type' => 'legacy'],
                );

                return true;
            });

            $adopted += $created ? 1 : 0;
        }

        $this->info('Adopted '.$adopted.' legacy attribution record(s).');

        return self::SUCCESS;
    }
}
