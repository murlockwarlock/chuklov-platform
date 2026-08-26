<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientTelegramAuthenticationRequest;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Support\Facades\DB;

class CompleteTelegramWebAuthentication
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly AuthenticateClientWithVerifiedChannel $authenticate,
    ) {}

    public function handle(string $token, VerifiedChannelIdentity $verifiedIdentity): Client
    {
        $token = trim($token);

        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) !== 1) {
            throw new InvalidTelegramWebAuthentication('The Telegram authentication token is invalid.');
        }

        return DB::transaction(function () use ($token, $verifiedIdentity): Client {
            $authenticationRequest = ClientTelegramAuthenticationRequest::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $authenticationRequest instanceof ClientTelegramAuthenticationRequest
                || $authenticationRequest->verified_at !== null
                || $authenticationRequest->consumed_at !== null
                || $authenticationRequest->expires_at->isPast()) {
                throw new InvalidTelegramWebAuthentication('The Telegram authentication token is invalid or expired.');
            }

            $this->organizationContext->set($authenticationRequest->organization);
            $client = $this->authenticate->handle(
                verifiedIdentity: $verifiedIdentity,
                acquisitionRequestId: (int) $authenticationRequest->getKey(),
            );

            if ((int) $client->organization_id !== (int) $authenticationRequest->organization_id) {
                throw new InvalidTelegramWebAuthentication('The Telegram authentication organization does not match.');
            }

            $identity = ClientChannelIdentity::query()
                ->where('organization_id', $authenticationRequest->organization_id)
                ->where('client_id', $client->getKey())
                ->where('channel', 'telegram')
                ->where('external_id', $verifiedIdentity->externalId)
                ->firstOrFail();

            $authenticationRequest->forceFill([
                'client_id' => $client->getKey(),
                'client_channel_identity_id' => $identity->getKey(),
                'verified_at' => now(),
            ]);
            $authenticationRequest->save();

            return $client;
        });
    }
}
