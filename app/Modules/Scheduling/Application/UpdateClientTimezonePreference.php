<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateClientTimezonePreference
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly OrganizationContext $context,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $timezone): Client
    {
        try {
            $timezone = IanaTimezone::from($timezone)->value;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['timezone' => 'The timezone must be an IANA identifier.']);
        }

        $client = $this->clientContext->client();

        return DB::transaction(function () use ($client, $timezone): Client {
            $lockedClient = Client::query()
                ->where('organization_id', $this->context->id())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedClient->timezone !== $timezone) {
                $lockedClient->forceFill(['timezone' => $timezone])->save();
                $this->audit->handle(
                    organization: $this->context->organization(),
                    actor: null,
                    action: 'client.profile.updated',
                    targetType: Client::class,
                    targetId: (string) $lockedClient->getKey(),
                    metadata: ['source' => 'portal', 'fields' => 'timezone'],
                );
            }

            return $lockedClient->refresh();
        });
    }
}
