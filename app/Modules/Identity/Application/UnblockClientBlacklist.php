<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\ClientRestrictionType;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientBookingRestriction;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UnblockClientBlacklist
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private OrganizationFeatureGate $features,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client): ClientBookingRestriction
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        return DB::transaction(function () use ($actor, $client, $organization): ClientBookingRestriction {
            $lockedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $restriction = ClientBookingRestriction::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $lockedClient->getKey())
                ->where('restriction_type', ClientRestrictionType::Blacklist->value)
                ->whereNull('unblocked_at')
                ->lockForUpdate()
                ->first();

            if (! $restriction instanceof ClientBookingRestriction) {
                throw ValidationException::withMessages([
                    'client' => 'Клиент не находится в чёрном списке.',
                ]);
            }

            $restriction->forceFill([
                'unblocked_by_user_id' => $actor->getKey(),
                'unblocked_at' => now(),
            ]);
            $restriction->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'client.blacklist.removed',
                targetType: ClientBookingRestriction::class,
                targetId: (string) $restriction->getKey(),
                metadata: [
                    'source' => 'crm',
                    'client_id' => $lockedClient->getKey(),
                    'old_blacklist_state' => true,
                    'new_blacklist_state' => false,
                    'reason_present' => filled($restriction->reason),
                ],
            );

            return $restriction->refresh();
        });
    }
}
