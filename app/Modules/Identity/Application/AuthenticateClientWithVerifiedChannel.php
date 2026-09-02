<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AuthenticateClientWithVerifiedChannel
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
        private readonly RegisterClientAcquisition $registerAcquisition,
    ) {}

    public function handle(
        VerifiedChannelIdentity $verifiedIdentity,
        ?string $acquisitionSessionId = null,
        ?int $acquisitionRequestId = null,
    ): Client {
        $organization = $this->context->organization();
        $this->features->authorize($organization, OrganizationFeature::ClientRecords);

        if ($verifiedIdentity->channel !== 'telegram'
            || trim($verifiedIdentity->externalId) === ''
            || mb_strlen($verifiedIdentity->externalId) > 191
            || trim($verifiedIdentity->displayName) === ''
            || mb_strlen($verifiedIdentity->displayName) > 160
            || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $verifiedIdentity->language) !== 1
        ) {
            throw new AuthorizationException('The verified channel identity is invalid.');
        }

        try {
            return $this->persist($verifiedIdentity, $acquisitionSessionId, $acquisitionRequestId);
        } catch (UniqueConstraintViolationException) {
            return $this->persist($verifiedIdentity, $acquisitionSessionId, $acquisitionRequestId);
        }
    }

    private function persist(
        VerifiedChannelIdentity $verifiedIdentity,
        ?string $acquisitionSessionId,
        ?int $acquisitionRequestId,
    ): Client {
        $organization = $this->context->organization();

        return DB::transaction(function () use ($organization, $verifiedIdentity, $acquisitionSessionId, $acquisitionRequestId): Client {
            $identity = ClientChannelIdentity::query()
                ->where('organization_id', $organization->getKey())
                ->where('channel', $verifiedIdentity->channel)
                ->where('external_id', $verifiedIdentity->externalId)
                ->lockForUpdate()
                ->first();

            if ($identity instanceof ClientChannelIdentity) {
                return $this->authenticateExistingIdentity($identity, $verifiedIdentity);
            }

            $client = new Client;
            $client->forceFill([
                'organization_id' => $organization->getKey(),
                'full_name' => $verifiedIdentity->displayName,
                'language' => $verifiedIdentity->language,
                'timezone' => $organization->defaultTimezone(),
                'lead_source' => $verifiedIdentity->channel,
            ]);
            $client->save();

            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'client.created',
                targetType: Client::class,
                targetId: (string) $client->getKey(),
                metadata: ['source' => $verifiedIdentity->channel],
            );

            if ($acquisitionSessionId !== null || $acquisitionRequestId !== null) {
                $this->registerAcquisition->handle(
                    organization: $organization,
                    client: $client,
                    sessionId: $acquisitionSessionId,
                    telegramAuthenticationRequestId: $acquisitionRequestId,
                );
            }

            $identity = new ClientChannelIdentity;
            $identity->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'channel' => $verifiedIdentity->channel,
                'external_id' => $verifiedIdentity->externalId,
                'external_username' => $verifiedIdentity->username,
                'verification_status' => ChannelIdentityStatus::Verified,
                'verification_method' => 'authenticated_channel_flow',
                'verified_at' => now(),
            ]);
            $identity->save();

            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'client.channel_identity.verified',
                targetType: ClientChannelIdentity::class,
                targetId: (string) $identity->getKey(),
                metadata: [
                    'channel' => $verifiedIdentity->channel,
                    'verification_method' => 'authenticated_channel_flow',
                ],
            );

            return $client->refresh();
        });
    }

    private function authenticateExistingIdentity(ClientChannelIdentity $identity, VerifiedChannelIdentity $verifiedIdentity): Client
    {
        if ($identity->verification_status === ChannelIdentityStatus::Revoked) {
            throw new AuthorizationException('This client channel identity is revoked.');
        }

        if ($verifiedIdentity->username !== null) {
            $identity->forceFill(['external_username' => $verifiedIdentity->username]);
        }

        if ($identity->verification_status === ChannelIdentityStatus::Unverified) {
            $identity->forceFill([
                'verification_status' => ChannelIdentityStatus::Verified,
                'verification_method' => 'authenticated_channel_flow',
                'verified_at' => now(),
            ]);
            $identity->save();

            $this->audit->handle(
                organization: $identity->organization,
                actor: null,
                action: 'client.channel_identity.verified',
                targetType: ClientChannelIdentity::class,
                targetId: (string) $identity->getKey(),
                metadata: [
                    'channel' => $identity->channel,
                    'verification_method' => 'authenticated_channel_flow',
                ],
            );
        } elseif ($identity->isDirty()) {
            $identity->save();
        }

        return $identity->client()->firstOrFail();
    }
}
