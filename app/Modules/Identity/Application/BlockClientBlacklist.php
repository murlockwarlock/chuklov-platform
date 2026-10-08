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

final readonly class BlockClientBlacklist
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private OrganizationFeatureGate $features,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, string $reason): ClientBookingRestriction
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Укажите причину длиной не более 500 символов.',
            ]);
        }

        return DB::transaction(function () use ($actor, $client, $organization, $reason): ClientBookingRestriction {
            $lockedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (ClientBookingRestriction::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $lockedClient->getKey())
                ->where('restriction_type', ClientRestrictionType::Blacklist->value)
                ->whereNull('unblocked_at')
                ->exists()) {
                throw ValidationException::withMessages([
                    'client' => 'Клиент уже находится в чёрном списке.',
                ]);
            }

            $restriction = new ClientBookingRestriction;
            $restriction->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $lockedClient->getKey(),
                'restriction_type' => ClientRestrictionType::Blacklist,
                'blocked_by_user_id' => $actor->getKey(),
                'reason' => $reason,
                'blocked_at' => now(),
            ]);
            $restriction->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'client.blacklist.added',
                targetType: ClientBookingRestriction::class,
                targetId: (string) $restriction->getKey(),
                metadata: [
                    'source' => 'crm',
                    'client_id' => $lockedClient->getKey(),
                    'old_blacklist_state' => false,
                    'new_blacklist_state' => true,
                    'reason_present' => true,
                ],
            );

            return $restriction->refresh();
        });
    }
}
