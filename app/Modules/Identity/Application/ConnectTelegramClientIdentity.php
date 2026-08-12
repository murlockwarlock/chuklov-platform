<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientChannelLinkToken;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ConnectTelegramClientIdentity
{
    public function __construct(
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $token, VerifiedChannelIdentity $verifiedIdentity): Client
    {
        if ($verifiedIdentity->channel !== 'telegram'
            || trim($verifiedIdentity->externalId) === ''
            || mb_strlen($verifiedIdentity->externalId) > 191) {
            throw new AuthorizationException('The Telegram identity evidence is invalid.');
        }

        $token = trim($token);

        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) !== 1) {
            throw new InvalidTelegramLinkToken('The Telegram connection token is invalid.');
        }

        try {
            return $this->persist($token, $verifiedIdentity);
        } catch (UniqueConstraintViolationException) {
            return $this->persist($token, $verifiedIdentity);
        }
    }

    private function persist(string $token, VerifiedChannelIdentity $verifiedIdentity): Client
    {
        return DB::transaction(function () use ($token, $verifiedIdentity): Client {
            $linkToken = ClientChannelLinkToken::query()
                ->where('token_hash', hash('sha256', $token))
                ->where('channel', 'telegram')
                ->where('flow', 'portal.telegram.connect')
                ->lockForUpdate()
                ->first();

            if (! $linkToken instanceof ClientChannelLinkToken
                || $linkToken->consumed_at !== null
                || $linkToken->expires_at->isPast()) {
                throw new InvalidTelegramLinkToken('The Telegram connection token is invalid or expired.');
            }

            $organization = $linkToken->organization;
            $client = $linkToken->client;
            $this->features->authorize($organization, OrganizationFeature::ClientRecords);

            $identity = ClientChannelIdentity::query()
                ->where('organization_id', $organization->getKey())
                ->where('channel', 'telegram')
                ->where('external_id', $verifiedIdentity->externalId)
                ->lockForUpdate()
                ->first();

            if ($identity instanceof ClientChannelIdentity
                && (int) $identity->client_id !== (int) $client->getKey()) {
                throw new AuthorizationException('The Telegram identity is already linked to another client.');
            }

            if (! $identity instanceof ClientChannelIdentity) {
                $identity = new ClientChannelIdentity;
                $identity->forceFill([
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'channel' => 'telegram',
                    'external_id' => $verifiedIdentity->externalId,
                ]);
            }

            if ($identity->verification_status === ChannelIdentityStatus::Revoked) {
                throw new AuthorizationException('The Telegram identity is revoked.');
            }

            if ($identity->verification_status !== ChannelIdentityStatus::Verified) {
                $identity->forceFill([
                    'verification_status' => ChannelIdentityStatus::Verified,
                    'verification_method' => 'telegram_connection_token',
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
                        'channel' => 'telegram',
                        'verification_method' => 'telegram_connection_token',
                    ],
                );
            }

            $linkToken->forceFill(['consumed_at' => now()]);
            $linkToken->save();

            return $client->refresh();
        });
    }
}
