<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ResetStagingClientAccount
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly PurgeStagingClientAccountData $purger,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client): void
    {
        if (! app()->environment(['local', 'staging', 'testing'])) {
            throw new AuthorizationException('The staging account reset is unavailable in this environment.');
        }

        $organization = $this->context->organization();

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        $purge = DB::transaction(function () use ($actor, $client, $organization): array {
            $lockedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $purge = $this->purger->handle($organization, $lockedClient);

            if (! $lockedClient->delete()) {
                throw new RuntimeException('The staging client account could not be deleted.');
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'client.staging_account.reset',
                targetType: Client::class,
                targetId: (string) $lockedClient->getKey(),
                metadata: ['deleted_record_count' => $purge['deleted_record_count']],
            );

            return $purge;
        });

        $this->purger->deleteStorage($purge);
    }
}
