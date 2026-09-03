<?php

use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'timezone_source') || ! Schema::hasTable('audit_events')) {
            return;
        }

        DB::table('audit_events')
            ->select(['id', 'organization_id', 'target_type', 'target_id', 'metadata'])
            ->where('action', 'client.profile.updated')
            ->where('target_type', Client::class)
            ->chunkById(500, function (Collection $events): void {
                $clientIdsByOrganization = [];

                foreach ($events as $event) {
                    $metadata = is_array($event->metadata)
                        ? $event->metadata
                        : json_decode((string) $event->metadata, true);
                    $fields = is_array($metadata) ? $metadata['fields'] ?? null : null;
                    $source = is_array($metadata) ? $metadata['source'] ?? null : null;
                    $targetId = (string) $event->target_id;

                    if (! in_array($source, ['portal', 'crm'], true)
                        || ! is_string($fields)
                        || ! in_array('timezone', array_map('trim', explode(',', $fields)), true)
                        || ! ctype_digit($targetId)
                        || (int) $targetId < 1) {
                        continue;
                    }

                    $clientIdsByOrganization[(int) $event->organization_id][] = (int) $targetId;
                }

                foreach ($clientIdsByOrganization as $organizationId => $clientIds) {
                    DB::table('clients')
                        ->where('organization_id', $organizationId)
                        ->where('timezone_source', 'organization')
                        ->whereIn('id', array_values(array_unique($clientIds)))
                        ->update(['timezone_source' => 'manual']);
                }
            }, 'id');
    }

    public function down(): void {}
};
