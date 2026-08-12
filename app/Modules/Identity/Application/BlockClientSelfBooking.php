<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
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
use InvalidArgumentException;

class BlockClientSelfBooking
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, string $reason): ClientBookingRestriction
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('The self-booking restriction reason is invalid.');
        }

        return DB::transaction(function () use ($actor, $client, $organization, $reason): ClientBookingRestriction {
            Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (ClientBookingRestriction::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->whereNull('unblocked_at')
                ->exists()) {
                throw ValidationException::withMessages([
                    'client' => 'The client is already blocked from self-service booking.',
                ]);
            }

            $restriction = new ClientBookingRestriction;
            $restriction->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'blocked_by_user_id' => $actor->getKey(),
                'reason' => $reason,
                'blocked_at' => now(),
            ]);
            $restriction->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'client.self_booking.blocked',
                targetType: ClientBookingRestriction::class,
                targetId: (string) $restriction->getKey(),
                metadata: ['source' => 'crm'],
            );

            return $restriction->refresh();
        });
    }
}
