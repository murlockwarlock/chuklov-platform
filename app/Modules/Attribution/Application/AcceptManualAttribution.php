<?php

namespace App\Modules\Attribution\Application;

use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptManualAttribution
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly RecordAuditEvent $audit,
        private readonly AttributionSourceDetail $detail,
    ) {}

    public function handle(Client $client, string $source, mixed $sourceDetail = null): ClientAttribution
    {
        $organization = $this->context->organization();
        abort_unless((int) $client->organization_id === (int) $organization->getKey(), 404);
        $source = trim($source);
        $canonicalSource = strtolower($source);
        $allowedSources = array_map('strtolower', config('attribution.manual_sources', []));

        if (! in_array($canonicalSource, $allowedSources, true)) {
            throw ValidationException::withMessages(['source' => 'Выберите источник из списка.']);
        }

        $attributes = $this->detail->attributes((int) $organization->getKey(), $source, $sourceDetail);

        return DB::transaction(function () use ($organization, $client, $source, $attributes): ClientAttribution {
            $lockedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $existing = ClientAttribution::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $lockedClient->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ClientAttribution) {
                return $existing;
            }

            $attribution = new ClientAttribution;
            $attribution->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $lockedClient->getKey(),
                'source_type' => 'manual',
                ...$attributes,
                'source' => $source,
                'capture_channel' => 'portal',
                'capture_context' => 'manual_fallback',
                'captured_at' => now(),
                'accepted_at' => now(),
            ]);
            $attribution->save();
            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'attribution.manual_source.accepted',
                targetType: ClientAttribution::class,
                targetId: (string) $attribution->getKey(),
                metadata: ['source_type' => 'manual'],
            );

            if (trim((string) $lockedClient->lead_source) === '') {
                $lockedClient->forceFill(['lead_source' => $source])->save();
            }

            return $attribution->refresh();
        });
    }
}
