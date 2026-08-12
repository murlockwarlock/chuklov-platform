<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RegisterClientChannelIdentity
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, string $channel, string $externalId): ClientChannelIdentity
    {
        $organization = $client->organization;
        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        $channel = Str::lower(trim($channel));
        $externalId = trim($externalId);

        if ($channel === '' || mb_strlen($channel) > 32 || preg_match('/^[a-z0-9._-]+$/', $channel) !== 1) {
            throw new InvalidArgumentException('The channel identifier is invalid.');
        }

        if ($externalId === '' || mb_strlen($externalId) > 191) {
            throw new InvalidArgumentException('The external identity is invalid.');
        }

        $identity = new ClientChannelIdentity;
        $identity->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'channel' => $channel,
            'external_id' => $externalId,
            'verification_status' => ChannelIdentityStatus::Unverified,
        ]);
        $identity->save();

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'client.channel_identity.registered',
            targetType: ClientChannelIdentity::class,
            targetId: (string) $identity->getKey(),
            metadata: ['channel' => $channel, 'verification_status' => ChannelIdentityStatus::Unverified->value],
        );

        return $identity->refresh();
    }
}
